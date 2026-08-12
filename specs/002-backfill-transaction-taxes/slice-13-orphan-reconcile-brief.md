# Slice 13 Architecture Brief — Orphan Reconcile, Stage 2 of 3 (T069, T071-partial)

**Date**: 2026-08-12 · **Status**: Approved to implement against this brief

## Why this brief exists, and what it does not cover

Stage 1 (Slice 12) archived every orphan row byte-faithfully and deleted nothing. This brief covers **Stage 2 only: reconcile** — deciding, per day, whether that day's still-live orphans are fully explained by (a) legitimate reconstructed replacements, (b) known-unreconstructable transactions, or (c) an unexplained content gap that must halt the run. **No delete logic. No CLI `--phase=delete`. Nothing in this slice removes a row from `transaction_taxes`.** Deletion is Stage 3 (T070/T070a), its own brief, its own review/revalidation moment, per the hard guardrail already established for this pipeline (slice-12-orphan-archive-brief.md).

This brief also does not implement `--apply`'s day-loop orchestration (T026/T030 already own that) — it implements the **reconciliation step** that the per-day loop calls after insert and before delete (plan.md's pipeline: `insert (9a) → reconcile (9b) → delete (9c)`).

## Prerequisite decisions this brief builds on (already made, not open here)

- **FR-014's tolerance**: reconstructed rows and orphans match when (`tax_type`, `amount`) are equal and `TIMESTAMPDIFF(SECOND, parent_transaction.created_at, orphan.created_at)` is in **[0, 10]**, one-directional (spec.md FR-014, decided 2026-08-12, research.md V5).
- **Three-way residual classification**: `no_replacement_exists` / `timestamp_out_of_tolerance` / `orphan_content_mismatch` (only the last halts) — spec.md FR-014.
- **No per-row transaction attribution, anywhere in this feature** (research.md V4 — re-keying orphans to a specific transaction via id-blocks/timestamp proximity was evaluated and explicitly rejected as an unreliable heuristic for financial data). Every design decision below operates on **counts within a (`tax_type`, `amount`, day) bucket**, never on a claim that "this specific orphan row is transaction X's."

## Scope contract

```text
Allowed:
- app/Services/Backfill/OrphanTaxReconciler.php (or equivalent name,
  keep consistent across service/command/tests) — day-scoped
  reconciliation per the algorithm below (T069).
- Wiring app/Console/Commands/ArchiveOrphanTaxRows.php's --phase=reconcile
  (currently rejected outright since Slice 12). Requires --day= (per
  cli-contract.md: --day is required for reconcile/delete, not archive).
- UPDATE (never DELETE) transaction_taxes_orphan_archive's
  reconciled_status/reason_code columns for that day's archived rows —
  these columns exist specifically for this stage (Slice 12/T066).
- Tests proving: correct classification on synthetic fixtures for each
  of the four outcomes (reconciled / timestamp_out_of_tolerance /
  no_replacement_exists / orphan_content_mismatch halts), the
  full-day-coverage precondition guard, and that nothing is deleted
  (T071's reconcile-side assertions; T071's delete-refusal assertions
  wait for Stage 3).

Not allowed:
- Any DELETE statement anywhere in this slice's code, against any table.
- --phase=delete, T070/T070a's deletion logic.
- Per-row transaction attribution / re-keying of any kind (research.md
  V4). If you find yourself writing code that claims "orphan row N
  belongs to transaction M," stop — that is out of scope and explicitly
  rejected.
- Any change to TaxBackfillRunner, TaxReconstructionService,
  TaxBackfillPreflightChecker, BackfillTransactionTaxes.php,
  VerifyTaxReconstruction.php, OrphanTaxArchiver.php — unrelated to
  this command's reconcile phase.
```

## Reconciliation algorithm (T069) — the core of this brief

Operates on a single day `D`, over the **whole day, all tenants** (see precondition below — never a tenant-scoped subset).

### Step 1 — Load candidates

- **Orphans for day D**: `transaction_taxes` WHERE `transaction_pk IS NULL` AND the row's own `created_at` falls in `[D 00:00:00, D+1 00:00:00)` — **no buffer, corrected 2026-08-12 (Code Reviewer finding on Slice 13's first implementation pass)**. An earlier version of this brief added a `+10s` upper-bound buffer to catch a transaction created in D's last few seconds whose orphan tax row was stamped just after midnight. That buffer was wrong: since day D and day D+1 are reconciled as separate, independent runs, a buffered orphan would be loaded (and given a verdict, and persisted) by **both** days' evaluations — and `persist()`'s unconditional `UPDATE ... WHERE original_id = <id>` means whichever day runs second silently clobbers the first day's verdict for that row, with no error. Worse, the buffer's fixed 10s width doesn't even cover the full measured tail (research.md V5 observed deltas up to 57s), so it didn't fully solve the problem it was trying to solve while introducing a real one.
  - **Accepted trade-off**: with no buffer, a transaction created in day D's last few seconds whose orphan tax row was stamped on day D+1 will not be found by either day's tolerance matching (day D's inserted-row population won't be in day D+1's query; day D+1 has no matching missing-payload transaction to explain it as content-gap either). This shows up as a small, unexplained content-gap surplus on day D+1, which — correctly, per this feature's whole design philosophy — **halts day D+1 for human review** rather than silently guessing which day the row belongs to. This is expected to be rare (a transaction has to land within single-digit seconds of midnight) and is a deliberately safer failure mode than the clobbering bug it replaces.
- **Inserted/reconstructed rows for day D**: `transaction_taxes` WHERE `transaction_pk IS NOT NULL` AND `created_at` falls in `[D 00:00:00, D+1 00:00:00)` — unchanged; by T026, inserted `created_at` is always exactly the parent's `created_at`, which is what defines "day D" in the first place.
- **Regression requirement**: a test must prove that a single orphan row is never loaded (or given a verdict) by more than one day's `evaluate()` call — i.e., day D's and day D+1's candidate queries are provably disjoint.

### Step 2 — Precondition guard (new; not previously specified — required for the Step 4 cross-check to be valid)

Before reconciling day D, verify every transaction dated on day D has a **terminal** `tax_backfill_records` outcome (`applied` / `skipped_existing` / `quarantined` / `failed` — never `pending`). If any are still `pending` (e.g. `apply()` was invoked tenant-scoped and hasn't covered every tenant active on day D yet), **refuse to reconcile that day** with a clear error naming the count still pending. Reconciling a partially-processed day would make Step 4's cross-check see transactions with no attempted replacement yet and misclassify their orphans as `no_replacement_exists` surplus that doesn't actually match expectation — a false halt, not a true one, but still wrong.

### Step 3 — Per-bucket tolerance matching

For each distinct (`tax_type`, `amount`) key present in either the orphan or inserted population for day D:

1. Sort that bucket's orphan `created_at` values ascending; sort that bucket's inserted `created_at` values (= their parent transactions' `created_at`) ascending.
2. Greedy two-pointer match: walk orphans in ascending order; for each orphan, claim the smallest **unclaimed** inserted timestamp `t` satisfying `0 <= orphan_ts - t <= 10`. (This is a standard maximum-cardinality greedy for one-directional interval matching against a sorted candidate set — provably optimal for this shape: since every window is `[t, t+10]` and both sequences are sorted, always taking the earliest feasible unclaimed candidate never blocks a better later match.)
3. Matched pairs → **reconciled**.

### Step 4 — Residual split within each bucket (revised per architect review — do not over-classify)

After Step 3, each bucket has some unpaired orphans and unpaired inserted rows left over (possibly zero of either).

1. Pair remaining unpaired orphans against remaining unpaired inserted rows in the **same bucket**, **ignoring the timestamp window entirely** this time, up to `min(unpaired_orphan_count, unpaired_insert_count)`. These pairs → **`timestamp_out_of_tolerance`** (a real reconstructed row of the right type/amount exists that day, it just didn't land within 10 seconds of any orphan after the tighter pass claimed the closer pairs).
2. Whatever orphan count remains **after that** (i.e., `unpaired_orphan_count - min(unpaired_orphan_count, unpaired_insert_count)`) is that bucket's **content-gap** count — there is no candidate of this (`tax_type`, `amount`) left in the day's inserted population at all, tolerance or not.

### Step 5 — Day-level content-gap cross-check (only place `no_replacement_exists` vs. `orphan_content_mismatch` is decided)

1. Sum Step 4.2's content-gap count across every bucket → `actual_content_gap(D)`.
2. Compute the day's **expected** content-gap independently, from data that has nothing to do with row-level matching:
   - `missing_payload_count(D)` = count of `tax_backfill_records` for day D with outcome `quarantined` and reason `missing_payload` (T013).
   - `ratio(D)` = `total_orphans(D) / total_affected_transactions(D)`, where `total_affected_transactions(D)` is the count of day-D transactions that had zero linked tax rows before this backfill began (the original defect population for that day — already knowable from the same source T013/T023 use). Per research.md V4, this ratio is observed to be a **clean integer on most days** (exactly 4 on several sampled days).
   - **`ratio(D)` MUST be a whole number for this cross-check to run at all.** If `total_orphans(D)` is not evenly divisible by `total_affected_transactions(D)`, do not round or approximate — treat the day as failing this check (go to step 4 below) and require human review. Silently rounding a fractional ratio is exactly the kind of guess this design is built to avoid.
   - `expected_content_gap(D) = missing_payload_count(D) * ratio(D)`.
3. If `actual_content_gap(D) == expected_content_gap(D)`: classify **all** of that day's content-gap orphans (across every bucket, collectively) as `no_replacement_exists`. This is a day-level verdict, not a per-row identification — no claim is made about which specific orphan belongs to which quarantined transaction.
4. If they don't match (including the non-integral-ratio case above): classify **all** of that day's content-gap orphans as `orphan_content_mismatch`, and **halt the run before any other day is touched**. Record `actual_content_gap(D)`, `expected_content_gap(D)` (or "ratio non-integral, N/A" if that's why it failed), and the day in the halt reason — this is what an operator needs to start investigating, even though the halt cannot point at a specific bad row.

### Step 6 — Persist verdicts

- For every orphan matched in Step 3: `UPDATE transaction_taxes_orphan_archive SET reconciled_status = 'reconciled' WHERE original_id = <orphan id>` (`reason_code` stays `NULL` — nothing to explain).
- For every orphan classified in Steps 4–5: `UPDATE ... SET reconciled_status = 'residual', reason_code = '<no_replacement_exists|timestamp_out_of_tolerance|orphan_content_mismatch>' WHERE original_id = <orphan id>`.
- These are the **same rows** Stage 1 already archived (matched by `original_id`) — this stage never inserts a new archive row, only updates the two columns Stage 1 deliberately left `NULL`.
- A day's reconcile MUST be all-or-nothing: if `orphan_content_mismatch` is found, do not partially persist `reconciled`/`timestamp_out_of_tolerance` verdicts for that day either — the whole day is unverified until it passes cleanly. (Simplest correct implementation: compute the full day's verdict in memory first, only write archive updates if the day as a whole passes.)
- **Chunked, not a single unbounded `whereIn`** (corrected 2026-08-12, Code Reviewer finding): a single day can carry over 100,000 orphan rows (research.md V4 — 06-25 alone has 132,672). Persisting a status group's ids MUST be chunked (this feature's established default of 1000, matching `OrphanTaxArchiver::DEFAULT_CHUNK_SIZE`), consistent with FR-005/R9's chunking discipline applied everywhere else in this feature — never a single unbounded statement over an unbounded id list. All chunks for a day still run inside the same all-or-nothing `DB::transaction()`.
- **Assert the affected-row count** (corrected 2026-08-12, Code Reviewer finding): each `UPDATE`'s reported affected-row count MUST equal the number of ids in that chunk. If it doesn't — e.g. because an orphan somehow has no corresponding Stage-1 archive row, which should never happen but must not fail silently — `persist()` MUST throw rather than report `persisted: true` having silently no-op'd on some rows. Fail loud, per this feature's established philosophy, rather than let a day appear successfully reconciled when its archive bookkeeping didn't actually happen.

## CLI wiring (`--phase=reconcile`)

- `--day=` becomes **required** when `--phase=reconcile` (cli-contract.md already specifies this; Slice 12 didn't need it since `archive` has no `--day`).
- Dry-run (no `--apply`): run the full algorithm read-only, report the day's verdict (reconciled/timestamp_out_of_tolerance/no_replacement_exists/orphan_content_mismatch counts, and pass/fail) — write nothing to the archive table.
- `--apply`: run the algorithm and persist Step 6's archive updates if the day passes; if it halts, write nothing (per Step 6's all-or-nothing rule) and exit non-zero.
- `--phase=delete` continues to be rejected outright in this slice (Stage 3, next).
- Same `buildResult()`/`render()` single-result-object convention as every other command in this feature.

## Test plan

- **Synthetic fixture coverage for all four outcomes**: a day where every orphan matches within 10s (pure reconciled); a day with some orphans matching only past 10s (`timestamp_out_of_tolerance`, bounded correctly by the `min(unpaired_orphan, unpaired_insert)` rule — include a regression test for the exact over-classification risk flagged in review: a bucket with 1,000 orphans and 950 inserted rows must yield at most 950 `timestamp_out_of_tolerance` and exactly 50 content-gap, never 1,000 `timestamp_out_of_tolerance`); a day whose content-gap exactly matches `missing_payload_count(D) * ratio(D)` (`no_replacement_exists`, non-halting); a day whose content-gap does NOT match expectation (`orphan_content_mismatch`, halts, nothing persisted); a day whose `ratio(D)` is non-integral (fails closed, halts, nothing persisted).
- **Precondition guard test**: a day with at least one `pending` `tax_backfill_records` row refuses to reconcile, with zero archive writes.
- **All-or-nothing persistence test**: on a halting day, assert zero `transaction_taxes_orphan_archive` rows changed (query the table before/after, not just the return value).
- **Nothing deleted**: assert `transaction_taxes` row count is unchanged before/after any reconcile run, apply or dry-run.
- **No per-row attribution leak**: grep-style/structural test that the implementation never joins/matches an orphan to a specific `transactions.id` — the design review guardrail made explicit as a regression test, not just a code-review note.
- **CLI-level**: `--day` required for `--phase=reconcile`; dry-run vs `--apply` counts agree structurally; `--json`/human output parity.

## What's explicitly deferred to Stage 3 (delete)

- T070's chunked per-day deletion, predicate `transaction_pk IS NULL`.
- T070a's 2026-06-13 wholesale residual delete (already reconciled here as `no_replacement_exists`, but not deleted until Stage 3 verifies the archive write and gates on it).
- T071's deletion-refusal tests (this slice only covers T071's reconcile-side assertions: nothing deleted, precondition enforced).
- The `--phase=delete --apply` authorization-token mechanism (T079/Architect Q4).
