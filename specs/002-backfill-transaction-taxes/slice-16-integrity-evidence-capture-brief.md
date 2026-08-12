# Slice 16 Architecture Brief — Pre-Run Integrity Evidence Capture (T075, T099)

**Date**: 2026-08-12 · **Status**: Approved to implement against this brief

## Why this brief exists

T076's post-run validation explicitly demotes the `(transaction_pk, tax_type)` duplicate check to "a secondary signal **compared against T075's baseline**" — because payloads may legitimately carry two rows of the same `tax_type` (T096), a bare "must be zero" assertion post-run would false-positive on data that was never wrong. Without a captured pre-run baseline, that comparison has nothing to compare against and T076 degrades back into the naive check it was explicitly designed to replace. Per T099, this baseline (and the complementary null/orphan integrity signal) must come from `php artisan txn:pk-integrity`, not a bespoke reimplementation.

**This slice covers T075/T099 only: capture and durably persist pre-run evidence.** It does not run `--apply`, does not touch the orphan pipeline (archive/reconcile/delete), and does not implement materiality or any post-run comparison — that comparison is T076's job, later, against whatever this slice persists.

## Grounding: a correctness issue found while designing this brief

T062's check is literally `GROUP BY (transaction_pk, tax_type) HAVING COUNT(*) > 1` with no stated `WHERE` clause. Applied naively to the **current** `transaction_taxes` table, this is wrong in a specific, dangerous way: MySQL's `GROUP BY` treats all `NULL` values of a grouping column as **one single group**. Today, 3,238,180 rows share `transaction_pk IS NULL` (research.md V4) — a naive `GROUP BY transaction_pk, tax_type` would collapse most of that orphan population into a handful of enormous "duplicate" groups (e.g. every orphan `VAT` row, regardless of which transaction it came from, landing in one group), producing a baseline that is meaningless noise, not evidence. **The query MUST filter `WHERE transaction_pk IS NOT NULL` before grouping.** This is not scope creep — it is what T062's own stated evaluation context (SC-002, linked-row duplication) actually requires, and it is not scoped to the defect window only: SC-002's post-run assertion is a whole-table check, so the pre-run baseline must use the identical whole-table scope for the two to be comparable.

## Scope contract

```text
Allowed:
- One new table + Eloquent model (schema below).
- app/Console/Commands/CaptureIntegrityEvidence.php
  (transactions:capture-integrity-evidence).
- Tests per the plan below.
- Invoking the EXISTING app/Console/Commands/TxnPkIntegrityReport.php
  via Artisan::call() and capturing its output verbatim (T099 — use it
  as the evidence source, never reimplement its null/orphan queries).

Not allowed:
- Any change to TxnPkIntegrityReport.php — this slice calls it, never
  edits it.
- Running --apply on the backfill, or any archive/reconcile/delete
  command (Slices 12-14, already complete) — this is read-only
  evidence capture, full stop.
- Materiality computation (T047) or any post-run comparison logic
  (T076) — this slice persists the `pre_run` evidence T076 will later
  read; it does not implement the comparison itself.
- Any write to transaction_taxes or transactions — this command's only
  write target is its own new table.
- Any change to SnapshotPreBackfillAggregates.php or its two tables
  (Slice 15, already complete and unrelated in shape/purpose to this
  evidence).
```

## Design decisions

### 1. Storage shape — one row per capture (simpler than Slice 15's run/record pair)

Unlike Slice 15's expensive, resumable, per-tenant-month capture, this evidence is two cheap-ish queries run together — there is no multi-step population to resume, so a single table suffices:

**`pre_run_integrity_captures`**

| Column | Purpose |
|---|---|
| `id` | PK |
| `window_start`, `window_end` | The `--from`/`--to` this capture is *for* (metadata only — see Design decision 2 for why the duplicate query itself is NOT scoped to this window) |
| `phase` | `pre_run` \| `post_run` — this slice only ever writes `pre_run`; the column exists now so T076 doesn't need a schema change later to record its own post-run capture through the same mechanism |
| `duplicate_check_summary` | `json` — `{total_duplicate_groups, total_duplicate_rows, sample}` from the corrected T062 query (Design decision 2); `sample` capped (e.g. 200 groups) as a defensive bound, not because a large result is expected |
| `integrity_report` | `text` — the **verbatim** console output of `php artisan txn:pk-integrity` (T099) |
| `captured_at` | |

No uniqueness constraint on `(window_start, window_end, phase)` — **multiple `pre_run` captures for the same window are allowed and expected** (e.g. one during rehearsal, a fresher one taken immediately before the real live run, since time passes and the live system keeps accumulating ordinary transactions in between). T076, later, is responsible for choosing the most recent `pre_run` capture as its comparison baseline — not this slice's concern.

### 2. The corrected duplicate-check query (T062/T075)

```sql
SELECT transaction_pk, tax_type, COUNT(*) AS cnt
FROM transaction_taxes
WHERE transaction_pk IS NOT NULL
GROUP BY transaction_pk, tax_type
HAVING COUNT(*) > 1
```

**Whole-table, not window-scoped** — SC-002's post-run assertion (T076) checks the whole `transaction_taxes` table, so the pre-run baseline must use the identical scope for the comparison to mean anything. `WHERE transaction_pk IS NOT NULL` is mandatory per the grounding section above — never remove it as a "simplification."

**Cost note**: this table holds tens of millions of rows system-wide (orphans plus every unrelated live transaction's linked rows). A `GROUP BY`/`HAVING` over that population is a real aggregate scan, not an indexed point lookup — run this command off-peak, same operational caution as every other command in this feature that touches `transaction_taxes` at scale.

### 3. Integrity report capture (T099)

```php
Artisan::call('txn:pk-integrity');
$output = Artisan::output();
```

Store `$output` verbatim in `integrity_report`. Do not parse or restructure it into JSON — `TxnPkIntegrityReport` has no `--json` mode and this slice must not modify it to add one (out of scope; it's a pre-existing, shared utility). Capturing its exact text is sufficient evidence and keeps this slice's blast radius to zero on existing code.

### 4. CLI wiring

```
transactions:capture-integrity-evidence
    {--from= : Window start (Y-m-d). Required — metadata only, not a query filter.}
    {--to= : Window end, exclusive (Y-m-d). Required.}
    {--phase=pre_run : pre_run | post_run. This slice only exercises pre_run.}
    {--apply : Persist. Without this flag: run both checks and display them, write nothing — this feature's established dry-run-by-default convention.}
    {--json}
```

Single `buildResult()`/`render()` pair, this feature's established one-result-object convention. Dry-run still **runs** both checks (they're read-only and cheap relative to the aggregate-snapshot path) so an operator can preview the actual numbers before deciding to persist — this differs from Slice 15's dry-run (which skips the expensive report calls entirely) because these two checks are inexpensive enough that previewing them costs nothing extra, and seeing them before persisting is more useful than a population-count-only preview would be.

## Test plan

- **Duplicate-check correctness — the NULL-collapse bug this brief exists to prevent**: seed several `transaction_pk IS NULL` orphan rows sharing the same `tax_type` (enough that a naive ungrounded `GROUP BY` would report them as one giant duplicate group) alongside zero genuine linked-row duplicates; assert `total_duplicate_groups = 0` — proving the `WHERE transaction_pk IS NOT NULL` filter is actually applied, not just documented.
- **Duplicate-check correctness — a real duplicate is still caught**: seed two `transaction_taxes` rows sharing an identical `(transaction_pk, tax_type)` pair; assert it appears in `duplicate_check_summary` with the correct count.
- **Integrity report is captured verbatim**: assert `integrity_report` contains the exact strings `TxnPkIntegrityReport` would print (e.g. run it independently in the test as the oracle and compare), not a re-derived summary.
- **`TxnPkIntegrityReport` is invoked, not reimplemented**: a structural/spy test asserting `Artisan::call('txn:pk-integrity')` actually happens (mirroring this feature's established rigor for "the real path was called, not bespoke logic").
- **Durable persistence**: after `--apply`, the row is queryable independent of the command process.
- **Multiple captures coexist**: two `--apply` invocations for the same window both persist distinct rows — no overwrite, no refusal.
- **Dry-run writes nothing**: `--apply` omitted still runs and displays both checks, but the table gains zero rows.
- **No mutation to `transaction_taxes`/`transactions`**: row counts unchanged before/after, for both dry-run and `--apply`.
- **CLI-level**: `--from`/`--to` required; `--json`/human output parity.

## What's explicitly deferred

- T076's actual post-run comparison logic (reading this slice's `pre_run` row, running the identical duplicate query again post-run, and reconciling against the persisted `orphan_content_mismatch`-free end state).
- The `post_run` phase's actual capture invocation — the column/enum value exists now so no schema change is needed later, but nothing in this slice ever writes `phase = 'post_run'`.
- T052/T054/T052a/T100/T102 (containment/rollback/backup readiness) — separate, later work per the user's own stated sequencing.
