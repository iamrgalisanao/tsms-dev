# Slice 14 Architecture Brief — Orphan Delete, Stage 3 of 3 (T070, T070a, T071, T079)

**Date**: 2026-08-12 · **Status**: Approved to implement against this brief

## Why this brief exists

This is the final stage of the orphan archive/reconcile/delete pipeline (T066-T072) and the **first slice in this feature that deletes anything from a live table**. Stage 1 (archive, Slice 12) and Stage 2 (reconcile, Slice 13) are both committed, reviewed, and architect-revalidated, and neither one ever issues a DELETE. Per the explicit staging guardrail set before Stage 1 began ("do not combine archive, reconcile, and delete into one broad slice... deletion deserves its own review/revalidation moment"), this brief covers delete **alone**, building on Stage 1/2's already-durable output rather than re-deriving anything.

## Prerequisite state this brief builds on (already implemented, not open here)

- **Stage 1** (`app/Services/Backfill/OrphanTaxArchiver.php`): every orphan (`transaction_taxes.transaction_pk IS NULL`) is copied into `transaction_taxes_orphan_archive` (unique on `original_id`), preserving `tax_type`/`amount`/`created_at`/`updated_at` verbatim. Archiving is whole-population, not day-scoped — by the time any day is reconciled, Stage 1 should already have archived it, but this brief does **not** assume that without checking (see Precondition 1 below).
- **Stage 2** (`app/Services/Backfill/OrphanTaxReconciler.php`): for a day whose reconciliation **passes**, every one of that day's archived rows gets `reconciled_status` set to `reconciled` or `residual` (with `reason_code` set to `no_replacement_exists` or `timestamp_out_of_tolerance` for the residual ones), written all-or-nothing. A day that **halts** (`orphan_content_mismatch`, non-integral ratio, or the precondition-pending guard) writes nothing — every one of its archived rows keeps `reconciled_status = NULL`.
- **This means**: "day D passed reconciliation" is fully, durably recoverable from the archive table alone — no NULL `reconciled_status` among day D's archived rows — without re-running `OrphanTaxReconciler::evaluate()`. This brief's precondition checks read the archive table directly; they do not call `evaluate()` again.

## Scope contract

```text
Allowed:
- app/Services/Backfill/OrphanTaxDeleter.php (new) — preflight(Carbon $day)
  and delete(Carbon $day, string $token, int $chunkSize) per the design
  below (T070, T070a, T079).
- Wiring app/Console/Commands/ArchiveOrphanTaxRows.php's --phase=delete
  (currently rejected outright since Slice 12/13).
- Tests per the plan below (T071 — deletion-refusal assertions this
  feature has been deferring since Slice 12/13 finally land here).

Not allowed:
- Any change to OrphanTaxArchiver.php or OrphanTaxReconciler.php — this
  slice only reads what they've already written (transaction_taxes_
  orphan_archive's persisted reconciled_status/reason_code); it does not
  need to re-run either of them.
- Any change to TaxBackfillRunner.php, TaxReconstructionService.php,
  TaxBackfillPreflightChecker.php, BackfillTransactionTaxes.php,
  VerifyTaxReconstruction.php.
- Deleting from transaction_taxes_orphan_archive, ever, under any
  circumstance — it is the permanent evidence record and outlives the
  live rows it describes (this is the entire point of archive-before-
  delete, FR-013).
- A day-level special case for 2026-06-13. T070a's stakeholder decision
  (archive-then-delete the 216 unrecoverable transactions' rows the same
  as every other day) is satisfied by this brief's uniform design, not a
  separate code path — see "2026-06-13" below.
```

## Design

### Precondition checks (`OrphanTaxDeleter::preflight(Carbon $day)`, pure read)

Both must pass before a token is even computed:

1. **Archive completeness for day D**: every row currently live in `transaction_taxes` with `transaction_pk IS NULL` and `created_at` in `[dayStart, dayEnd)` (the identical strict range Stage 2 uses — no buffer, per Slice 13's day-boundary fix) must have a matching row in `transaction_taxes_orphan_archive` (`archive.original_id = live.id`). If any live orphan for day D has no archive row at all, refuse — Stage 1 hasn't actually covered this day yet (should never happen given Stage 1's whole-population design, but this brief does not assume it, per the Prerequisite section above).
2. **Reconciliation pass for day D**: every row in `transaction_taxes_orphan_archive` with `created_at` in `[dayStart, dayEnd)` must have a non-NULL `reconciled_status`. If any is NULL, refuse — day D either was never reconciled or its reconciliation halted.

If either check fails, `preflight()` returns a refusal result (no hash, no preview count) and `delete()` must refuse to run at all for that day.

### Evidence hash (T079 — the enforced authorization mechanism)

If both preconditions pass, compute a deterministic hash over day D's **archived, reconciled** verdict — not over anything in `transaction_taxes` (which is about to be mutated) or anything in `tax_backfill_records` (which Stage 2 already consumed):

```php
$dayKey = $day->format('Y-m-d');

$rows = DB::table('transaction_taxes_orphan_archive')
    ->where('created_at', '>=', $dayStart)
    ->where('created_at', '<', $dayEnd)
    ->orderBy('original_id')
    ->get(['original_id', 'reconciled_status', 'reason_code']);

$canonical = $dayKey.'|'.$rows->map(fn ($r) => "{$r->original_id}:{$r->reconciled_status}:".($r->reason_code ?? 'NULL'))->implode('|');
$hash = hash('sha256', $canonical);
```

**The day is part of the hashed input, not just an argument to the function** (added per architect review) — the canonical string is prefixed with `$dayKey` before the row tuples, so the hash is self-describing and bound to the specific day it authorizes. Without this, a token is only accidentally non-reusable across days because `original_id` happens to be globally unique — correct today, but a coincidence the design shouldn't quietly depend on. With the day baked in, passing day D's captured token to `--day=D+1 --apply` fails the comparison structurally, not by luck. Explicitly sorted by `original_id` (never relies on insertion/scan order) and built from a fixed string format (never `json_encode`, whose key-ordering behavior is a needless determinism risk here). This is `preflight()`'s output — it is also what an operator obtains by running `--phase=delete --day=D` (dry-run, no `--apply`) and is expected to hold onto until they're ready to authorize the actual delete. **This brief deliberately does not add a hash-generation step to Stage 2's already-committed, already-reviewed `OrphanTaxReconciler`/CLI code** — `preflight()`'s dry-run output is the single place this hash is ever produced or displayed, keeping Stage 2 untouched.

Because the hash is computed purely from `transaction_taxes_orphan_archive` (permanent, append-only-then-status-updated-once, never touched by delete itself), it is stable across repeated `preflight()` calls for the same day as long as nothing has changed the archive's verdict for that day since — and it changes if, and only if, something did (e.g. an operator somehow re-ran reconcile with a different outcome, which should not happen for an already-passed day, but this is exactly the drift this mechanism exists to catch).

### `delete(Carbon $day, string $token, int $chunkSize)`

1. Re-run the two precondition checks. Refuse (zero deletes) if either fails.
2. Recompute the hash fresh, exactly as above. Refuse (zero deletes, clear error naming both hashes are not shown for security/log-hygiene reasons — just "token mismatch") if it does not equal `$token`.
3. Load day D's archived, reconciled `original_id`s (ascending, same query as the hash computation) and chunk them (`chunkSize`, default matching this feature's established 1000).
4. For each chunk: `DELETE FROM transaction_taxes WHERE id IN (<chunk ids>) AND transaction_pk IS NULL` — the `transaction_pk IS NULL` clause is kept as a belt-and-braces guard even though the ids all came from the orphan archive; it must never be removed as an "optimization." Never a single unbounded `DELETE` (T070, FR-015).
5. **Idempotency, deliberately not an exact-count assertion**: unlike Stage 2's `persist()` (which asserted an *exact* affected-row count because every id there was guaranteed to have a corresponding archive row that had never been touched), a chunk here may legitimately affect **fewer** rows than its id count on a re-run — some of this chunk's rows may already have been deleted by an earlier, interrupted `delete()` call. That is success, not an error: `transaction_pk IS NULL` structurally guarantees a linked row is never at risk regardless, and "delete rows matching this predicate, however many remain" is correctly idempotent as written. Record each chunk's actual affected count in the returned summary for the operator's own audit trail, but do not fail on it being less than the chunk size.
6. Return a summary: `{day, token_verified: true, chunks_processed, rows_deleted, already_deleted}` (`already_deleted` = the running total of "chunk id count minus chunk affected count," so an operator re-running after an interruption can see how much was already done versus newly finished this run).

### 2026-06-13 (T070a)

No special case exists anywhere in this design. Once 2026-06-13 has passed Stage 2's reconciliation (its content-gap explained as `no_replacement_exists` for the 216 unrecoverable transactions, per FR-014/T069), its archived rows carry `reconciled_status = 'residual'`/`reason_code = 'no_replacement_exists'` exactly like the mechanism above expects, and `delete()` removes them from the live table the same way as any other day's `reconciled`/`timestamp_out_of_tolerance` rows. T070a's stakeholder decision (archive-then-delete rather than permanent live retention) is satisfied by this uniform mechanism; this brief adds a dedicated test proving it (see Test plan) rather than a distinct code path.

## CLI wiring (`--phase=delete`)

```
transactions:archive-orphan-taxes
    {--phase=delete}
    {--day= : required}
    {--apply : Persist. Without this flag: preflight only, prints the current evidence hash and a delete preview, deletes nothing.}
    {--token= : Required when --apply is set. Must equal the hash preflight() reports for this day.}
    {--chunk=1000}
    {--json}
```

- Dry-run (no `--apply`): calls `preflight()` only. Reports precondition status, the current evidence hash (labeled clearly, e.g. `authorization_token`), and a preview count of how many rows would be deleted (the same query `delete()` would chunk over, counted not deleted). Never requires `--token`.
- `--apply` without `--token` (or with `--token=` empty): rejected before any DB access, mirroring this command's existing validate-before-DB-access convention.
- `--apply` with `--token=`: calls `delete()`. A token mismatch or failed precondition must produce **zero** rows deleted — verify this via the database in tests, not the return value alone (this feature's established verification standard since Slice 12).
- Same single `buildResult()`/`render()`-per-phase convention as `archive`/`reconcile` (a new `buildDeleteResult()`/`renderDelete()` pair).

## Test plan

- **Precondition 1 (archive incomplete)**: a live orphan for day D with no corresponding archive row → `preflight()` refuses, no hash returned; `delete()` (with any token) performs zero deletes.
- **Precondition 2 (reconciliation not passed)**: day D's archived rows include at least one `reconciled_status IS NULL` → same refusal, zero deletes.
- **Token mismatch**: preconditions pass, but a stale/wrong token is supplied → `delete()` refuses, zero rows touched, verified against the database (not just the return value).
- **Token match — happy path**: preconditions pass, correct token supplied → every one of day D's archived, reconciled live orphan rows is deleted; the archive rows themselves are untouched (still present, `reconciled_status` unchanged); rows outside day D (adjacent days' orphans) are untouched.
- **Never touches a linked row**: seed `transaction_pk NOT NULL` rows dated within day D (including ones sharing `tax_type`/`amount` with deleted orphans) — assert they all survive `delete()` untouched.
- **2026-06-13 / `no_replacement_exists` uniform deletion**: a day whose archived rows are entirely `residual`/`no_replacement_exists` (simulating the 216-transaction residual) deletes identically to a day of plain `reconciled` rows — no code-path difference, proven by running the same assertions against both fixture shapes.
- **Chunking**: enough rows to require multiple chunks (small `chunkSize` in the test) — assert every intended row is gone, not just some, and that no single `DELETE` statement handled the whole set (e.g. via query-log inspection).
- **Idempotency**: run `delete()` twice with the identical token. First run deletes everything; second run reports `rows_deleted: 0`, `already_deleted` reflecting the full count, and still returns success — not an error.
- **Hash determinism**: two `preflight()` calls against the same, unchanged day return the identical hash; a fixture where one archived row's `reconciled_status`/`reason_code` differs produces a different hash than an otherwise-identical fixture.
- **Hash is day-bound, not just accidentally unique**: two different days whose archived rows are given the same `reconciled_status`/`reason_code` shape still produce different hashes (because the day itself is part of the hashed input) — construct this with two small fixture days sharing the same status/reason_code pattern and assert their `preflight()` hashes differ; separately, assert that day D's captured token is rejected by `delete()` when supplied against `--day=D+1`, even if D+1's own preconditions independently pass.
- **CLI-level**: `--day` required; `--apply` without `--token` rejected before DB access; dry-run never requires or accepts deletion; `--json`/human output parity; `--phase=archive`/`--phase=reconcile` continue to behave exactly as before (no shared-code regression).

## What this brief completes

T070, T070a, T071, T079, and — with this stage done — the entire T066-T072 orphan pipeline. **No further orphan-archive/reconcile/delete work remains after this slice**, other than whatever Documentation Sync and final tasks.md/spec status updates follow it. This remains, as throughout, subject to the same rule as every other slice in this feature: **no live `--apply` against real production/staging data runs without the user's separate, explicit authorization** — this brief authorizes writing and reviewing the code, not running it against real data.
