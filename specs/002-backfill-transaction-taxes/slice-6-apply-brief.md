# Slice 6 Architecture Brief — Apply Insert Path (T026-T029, T034-T035)

**Date**: 2026-08-11 · **Status**: Approved to implement against this brief

## Why this brief exists

Slices 1-5 (`TaxReconstructionService`, audit persistence, `TaxBackfillRunner::dryRun()`, the dry-run CLI, the verification oracle) are all read-only or audit-only with respect to `transaction_taxes`. **Slice 6 is the first code in this feature that writes a real tax row.** That crossing point gets a tighter scope contract and explicit invariants before implementation, rather than being scoped inline in an implementation prompt like prior slices.

## Scope contract

```text
Allowed:
- Insert reconstructed linked transaction_taxes rows for eligible transactions.
- Write TaxBackfillRecord outcomes.
- Enforce idempotency / no-overwrite.
- Use the parent transaction's own created_at for inserted tax rows.
- Batch inserts safely (per-transaction multi-row insert at minimum).
- Prove --apply twice creates zero duplicates.
- Prove pre-existing linked rows are untouched.

Not allowed:
- Delete orphan rows (T070/T070a — separate slice).
- Archive orphan rows (T066-T068 — separate slice).
- Refresh aggregates (T039 — separate slice).
- Materiality calculation (T046-T051 — separate slice).
- Enable broad whole-window apply — this feature's apply path is
  day-scoped by design (FR-014a, cli-contract.md's "Day-scoped apply"
  guarantee: "--apply MUST require --day... A whole-window --apply MUST
  be rejected").
- Modify or delete any existing linked transaction_taxes row, ever.
- Wire --apply into the CLI command (BackfillTransactionTaxes.php stays
  exactly as Slice 4 left it — --apply still rejected outright there).
  This slice adds apply *capability* to TaxBackfillRunner; exposing it
  through the CLI, with --day enforcement at that layer, is deliberately
  a separate follow-up task, not bundled in here.
```

## Hard architectural invariants

- Insert only when the transaction has **zero linked** tax rows (`taxes()->exists()` is false) — checked first, before reconstruction runs at all, same ordering `dryRun()` already uses.
- NULL-keyed orphans are never "already exists" for this predicate — they're a different, later slice's problem (T070+).
- Never `UPDATE` or `DELETE` any existing linked `transaction_taxes` row, under any code path in this class.
- Every inserted row's `transaction_pk` must be asserted non-null immediately before the insert — a defensive check, not just an assumption, per data-model.md's own warning that the column is nullable at the DB level (that nullability is exactly how the original 3.24M-row defect happened).
- `created_at` on every inserted row is the **parent transaction's own `created_at`** — never `now()`. This is a repeatedly-emphasized, previously-corrected requirement in this feature (FR-014, data-model.md, T068a) precisely because using insertion time would fail day-level reconciliation by construction.
- Quarantined/failed transactions write an audit record only — never a tax row.
- Re-running apply over the same scope converges every previously-applied transaction to `skipped_existing` on the second pass — idempotent by construction via the same-existing-linked-rows check, not by a separate dedup step.
- All writes happen in bounded chunks; no window-wide (or day-wide, for a large day) transaction.

## Technical decisions this brief makes (so the implementer isn't guessing)

1. **New method, not a mode flag on `dryRun()`**: add `TaxBackfillRunner::apply(Carbon $day, ?int $tenantId = null, ?int $limit = null, int $chunkSize = 500): TaxBackfillRun`. Single `Carbon $day` parameter (not `$from`/`$to`) makes day-scoping structural, not just enforced by convention — there is no way to call this method with a multi-day window. `dryRun()` itself is untouched.

2. **Per-transaction atomicity, not per-chunk.** Slice 3's dry-run wrapped each chunk's audit writes in one `DB::transaction()`, and its own Architect drift-revalidation flagged that this risks losing an entire chunk's already-written rows if one transaction's processing escapes containment mid-chunk. That risk was acceptable for audit-only rows. It is not acceptable here: a chunk's already-successfully-inserted **real tax rows** must never be rolled back because a *different* transaction in the same chunk hit a DB error. Wrap each transaction's own `transaction_taxes` insert + its `TaxBackfillRecord` write in **one short transaction per transaction processed**, not one per chunk. This costs more round-trips than chunk-level wrapping but is the correct tradeoff once real financial writes are on the line.

3. **Reuse `DeadlockRetryService`** for the actual insert, per this repo's established convention for safe writes against contended tables (`CLAUDE.md`'s architecture notes, `research.md` R9's chunking-discipline rationale). Do not reuse `TransactionIngestService::insertTaxes()` itself or extract from it — that decision (R5/Q8/N10) was already made and applies here too: this class's insert semantics (parent-`created_at` stamping, the different validation guard, day-scoping) diverge from the live path.

4. **Failure isolation mirrors Slice 3's fixed design, adapted for real writes**: a transaction whose processing fails is still recorded as `failed` (audit only, no tax row — it never reached the insert), and one transaction's failure must not abort the rest of the day's scan. Apply Slice 3's two-layer containment (inner guard around the failure-recording write itself; outer guard around the whole scan marking the run `interrupted` if something still escapes) to this method too, adjusted for per-transaction (not per-chunk) transaction boundaries.

5. **Batching**: at minimum, all of one transaction's reconstructed rows go in a single multi-row `insert()`, not one statement per row. Full cross-transaction batching (multiple transactions' rows in one statement, T078's later optimization against the ~6.5M-statement concern) is explicitly **not** required for this slice — note it as deferred to T078 rather than attempting it here.

## Test plan (T034, T035, plus baseline correctness)

- A transaction with zero linked rows, valid payload, clean cross-check → tax rows actually inserted, `created_at` matches the parent transaction's, outcome `applied`.
- **T034**: apply the same day twice → second pass reports every transaction from the first pass as `skipped_existing`; zero duplicate rows in `transaction_taxes` (assert by count, scoped to the test's own transaction ids per the known `RefreshDatabase` isolation bug).
- **T035**: a transaction with a pre-existing linked row (simulating prior manual correction) is never touched — no insert attempted, no reconstruction/cross-check even run for it, outcome `skipped_existing`.
- Quarantined/failed transactions during an apply run produce zero tax rows.
- A single transaction's insert failure (real DB-level error, not a mocked exception — same rigor as Slice 3's fix) doesn't roll back another transaction's already-committed insert in the same day/chunk, and the run doesn't get stuck at `running`.
- `transaction_pk` non-null assertion: prove the code path that would refuse an insert if that assertion ever failed (defensive test, even though it shouldn't be reachable given the upstream checks).

## What's explicitly deferred to later slices

- Orphan archive/reconcile/delete (T066-T072).
- Throttling / kill-switch / resume beyond what idempotency already provides for free (T030-T033).
- Wiring `--apply` into the CLI with `--day` enforcement at that layer.
- Cross-transaction batch inserts (T078).
- Aggregate refresh, materiality, US2/US3/US4.
