# Slice 10 Architecture Brief — Command Pre-Flight Completion (T018)

**Date**: 2026-08-11 · **Status**: Approved to implement against this brief

## What's already done vs. what's actually new here

T018's original text: *"Implement pre-flight validation (required columns present, window parseable, `--to` after `--from`); return `FAILURE` before any mutation."*

**Already satisfied, no new work needed** — re-verified against the current `BackfillTransactionTaxes::validateInput()` (Slices 4 & 8): window-format validation (`--day`/`--from`/`--to` all validated as `Y-m-d`), `--to` not before `--from`, empty/inverted-window rejection, mutually-exclusive `--day` vs `--from`/`--to` forms, `--apply` requiring `--day`, and positive-integer validation for `--tenant`/`--chunk`/`--limit`/`--throttle`. All of this already runs, and already returns `FAILURE` before the runner is touched, for both dry-run and apply. **Do not re-implement or restructure any of this.**

**Genuinely new**: *"required columns present"* — nothing in this feature currently verifies that the pre-existing `transactions`/`transaction_taxes` tables (which this feature reads from and writes to, but doesn't own the schema of) actually have the columns the code assumes exist. T097 (Slice 9) checks `transaction_taxes`'s index/FK — a different, narrower concern, apply-only. This slice checks column *existence* across both tables this feature's read path (`TaxReconstructionService`) and write path (`TaxBackfillRunner::insertTaxRows()`) depend on, for **both** `dryRun()` and `apply()` — because a missing column would break dry-run too (it would just fail with a raw, confusing SQL error instead of a clean one), not only apply.

## Scope contract

```text
Allowed:
- A required-columns existence check, run at the start of BOTH
  TaxBackfillRunner::dryRun() and TaxBackfillRunner::apply(), before
  either builds its chunked query.
- Fail cleanly (a new/reused terminal TaxBackfillRun status, zero
  transactions touched) if any required column is missing.
- Record the check's result on the run, same infrastructure Slice 9
  already built for T097's checks.
- Tests proving a missing required column fails both dry-run and apply
  before the runner scans anything or writes anything.

Not allowed:
- Orphan archive/reconcile/delete (T066-T072).
- Aggregate refresh, materiality.
- Any change to TaxBackfillPreflightChecker's existing index/FK/
  nullability logic (T097, Slice 9) — that stays apply-only, unchanged.
  This slice adds a second, narrower, both-paths check alongside it,
  not a replacement or restructuring of it.
- Any change to validateInput()'s existing window/format validation —
  already correct, out of scope to touch.
```

## Required columns to check

`transactions`: `id`, `tenant_id`, `created_at`, `original_payload`, `vatable_sales`, `vat_amount`, `sc_vat_exempt_sales` — the columns `TaxReconstructionService::reconstructTaxRows()`/`crossCheck()` and `TaxBackfillRunner::insertTaxRows()` actually read.

`transaction_taxes`: `id`, `transaction_pk`, `tax_type`, `amount`, `created_at` — the columns `insertTaxRows()` writes and `TaxReconstructionService::crossCheck()`/the oracle command read back. (`updated_at` is already conditionally checked via `Schema::hasColumn()` inside `insertTaxRows()` itself — leave that as-is, don't duplicate it here.)

Do **not** check `tax_backfill_runs`/`tax_backfill_records` — those are this feature's own migrated tables; if they're missing, the very first `TaxBackfillRun::create()` call already fails immediately and unambiguously (a missing-table SQL error is not the confusing case this slice exists to prevent — that confusing case is a column silently missing on a table this feature doesn't control).

## Design decisions

1. **Extend `TaxBackfillPreflightChecker`** with a second public method (e.g. `checkRequiredColumns(): array`) rather than creating a new class — same schema-introspection domain, reuse `Schema::getColumns()`/`Schema::hasColumn()`, whichever is more efficient for checking a known list of columns across two tables. Return shape: something like `{missing: array<string> (e.g. "transactions.vat_amount"), passed: bool}`. Keep this method and `check()` (T097's existing index/FK/nullability method) independent of each other — different callers, different gating rules, don't merge them into one combined method.

2. **`dryRun()` gains this check** (its first use of `TaxBackfillPreflightChecker` — previously it never called this class at all, per Slice 9's explicit "dry-run untouched" scoping, which stays true for T097's `check()` specifically but not for this new column check). Immediately after creating the `TaxBackfillRun` row (`mode = dry-run`, `status = running`), call `checkRequiredColumns()`, record the result on `preflight_checks` (reuse the same column Slice 9 added — see decision 4 below for the combined shape), and if it fails: set `status = TaxBackfillRun::STATUS_PREFLIGHT_FAILED` (reuse Slice 9's status, don't add a new one — a missing column is exactly as much a "pre-flight failed" condition as a missing index/FK), `completed_at = now()`, return immediately — the chunked query must never be built.

3. **`apply()` gains this check too**, run alongside (not instead of) T097's existing `check()`. Order: run `checkRequiredColumns()` first (a missing column is a more fundamental problem than a missing index), then `check()` only if that passes (no point checking an index on a column that might not even exist, though in practice `idx_tx_taxes_pk`/`fk_tx_taxes_pk` are on `transaction_pk`, not one of the newly-checked columns — order defensively anyway). Either check failing → `STATUS_PREFLIGHT_FAILED`, recorded, zero transactions touched.

4. **`preflight_checks` column's shape, now that two different checks populate it**: restructure to a small envelope, e.g. `{'required_columns' => {...checkRequiredColumns() result...}, 'schema_integrity' => {...check() result, apply-only, absent/null for dry-run...}}`. This changes the shape Slice 9 shipped (which stored `check()`'s result directly, flat) — that's an acceptable, intentional evolution since T018 was always going to need to share this column, but update Slice 9's own tests if their assertions on the flat shape now need adjusting for the new envelope. Document the new shape clearly in both classes' docblocks and in `TaxBackfillRun`'s own docblock/migration comment if one exists.

5. **CLI surface**: `BackfillTransactionTaxes::render()`'s pre-flight-failure output (built in Slice 9) must now handle both failure reasons — missing columns and missing index/FK — and name whichever actually failed. No new exit code needed; `STATUS_PREFLIGHT_FAILED` already maps to exit code `4` from Slice 9, and that mapping is correct for both new failure reasons too.

## Test plan

- **Pass case, both modes**: `dryRun()` and `apply()` against today's real schema (all required columns present) — both proceed normally, `preflight_checks` shows `required_columns.passed: true` for both; `apply()`'s run also shows `schema_integrity.passed: true`.
- **Forced failure — missing column, dry-run**: drop a required column from `transactions` (pick one, e.g. `vat_amount`) via `DB::statement('ALTER TABLE transactions DROP COLUMN vat_amount')` in a test, run `dryRun()`, assert `STATUS_PREFLIGHT_FAILED`, zero `TaxBackfillRecord` rows, `preflight_checks.required_columns.missing` names `transactions.vat_amount`. **Restore the column in a `finally` block, verified before the test ends** — same discipline Slice 9 established for its own destructive tests (this repo's `RefreshDatabase` isolation bug means you cannot rely on automatic rollback; verify restoration with a real `Schema::hasColumn()` assertion inside `finally` itself). Restoring a dropped column loses its data type/nullability/default if you don't specify them explicitly when re-adding — capture the column's exact definition before dropping it (via `Schema::getColumns()`) so the restore statement recreates it faithfully, not just as "a column with this name."
- **Forced failure — missing column, apply**: same pattern, proving `apply()` also fails cleanly before scanning/writing anything.
- **`apply()`-specific ordering**: a schema state where `checkRequiredColumns()` fails but `check()` (T097's index/FK check) would have passed — confirm the run still ends `STATUS_PREFLIGHT_FAILED` with the column failure recorded, and `check()` is never even called (or if it is called defensively, its result doesn't misleadingly claim a pass when the run never got that far — your call on whether to skip calling `check()` at all when columns are already known-missing, but be deliberate about it either way and document the choice).
- **Slice 9 regression**: re-run Slice 9's own pre-flight tests (index/FK missing cases) and confirm they still pass given the `preflight_checks` shape change — update their assertions to read from the new `schema_integrity` sub-key rather than the top level.

## What's explicitly deferred

- Orphan archive/delete, aggregate refresh, materiality — unrelated, unchanged.
- Any further pre-flight fact beyond the specific column list named above — don't expand scope speculatively.
