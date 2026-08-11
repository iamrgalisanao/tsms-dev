# Slice 9 Architecture Brief — Schema Pre-Flight (T097)

**Date**: 2026-08-11 · **Status**: Approved to implement against this brief

## Why this brief exists

Slice 8 wired `--apply` into the CLI and honestly disclosed, in `--help` output itself, that T097's schema pre-flight checks don't exist yet. Now that `--apply --day=<date>` can perform a real write, that disclosed gap is the next safety boundary to close — before US2/US3/US4, before archive/delete, before anything else. This slice closes it.

## Scope contract

```text
Allowed:
- A schema pre-flight check, run at the start of TaxBackfillRunner::apply()
  (not dryRun() — this guards the mutation path specifically), before any
  transaction is scanned.
- Fail the run (zero transactions touched, zero chunks processed) if a
  required structural precondition is missing.
- Record every observed fact (not just pass/fail) on the run record.
- Tests for both the pass case (today's actual schema) and forced-failure
  cases (index/FK missing).

Not allowed:
- Orphan archive/reconcile/delete (T066-T072).
- Aggregate refresh (T039).
- Materiality (T046-T051).
- Any change to dryRun(), TaxReconstructionService, or the existing
  classification/insert logic inside apply() itself — this slice adds a
  gate in front of that logic, it doesn't touch what's behind the gate.
```

## What gets checked, and which facts gate vs. merely get recorded

Per T097's original text and confirmed against the actual migration (`database/migrations/2025_08_13_000012_add_transaction_pk_foreign_keys.php`) that created these objects on `transaction_taxes`:

| Fact | Name | Gates the run? | Why |
|---|---|---|---|
| Index presence | `idx_tx_taxes_pk` on `transaction_pk` | **Yes — fail if missing** | Without it, every delete chunk (a later slice's job, but the index must exist regardless) is a full table scan against a 3M+ row table. This is a hard precondition for this feature's whole chunking strategy to remain safe, even though this slice doesn't itself delete anything. |
| FK presence | `fk_tx_taxes_pk` on `transaction_pk` → `transactions.id` | **Yes — fail if missing** | Referential integrity backstop for the exact defect class this feature exists to fix. |
| FK's `ON DELETE` action | (read from `fk_tx_taxes_pk`) | **No — record only** | Informational. Confirmed today (by reading the migration directly) to be `CASCADE`, not `SET NULL` — this resolves an open question flagged in earlier drift revalidations (Slice 2/6 notes worrying that `SET NULL` could reintroduce NULL-keyed rows via `transactions` deletion; `CASCADE` deletes the child row instead, so that specific concern doesn't apply). Record it so this is verified at runtime, not just trusted from reading one migration file once. |
| `transaction_pk` nullability | column-level nullable flag | **No — record only** | This feature's entire premise is that `transaction_pk` **is** currently nullable (that's the defect condition, not something to fail on — failing here would make `apply()` permanently unusable). Record it as an observed fact so the run's audit trail is honest about the schema state it ran against, and so a future run against a hardened (`NOT NULL`) schema is visibly distinguishable from today's. |

**Pass/fail rule**: the run proceeds only if both `idx_tx_taxes_pk` and `fk_tx_taxes_pk` are present. Either missing → the run fails immediately, before any transaction is scanned, in a new `TaxBackfillRun::STATUS_PREFLIGHT_FAILED` state — distinct from `failed` (a processing error), `interrupted` (an unanticipated crash), and `stopped` (a deliberate operator stop), so an operator/script can tell "the schema isn't safe to run against" apart from all three of those.

## Design decisions

1. **New service**: `app/Services/Backfill/TaxBackfillPreflightChecker.php` (or a name you prefer, kept in the same namespace) with one public method returning a small, structured result — a plain array or a lightweight value object, your call — containing at minimum: `index_present` (bool), `fk_present` (bool), `fk_on_delete_action` (string|null), `transaction_pk_nullable` (bool), and `passed` (bool, `index_present && fk_present`). Use Laravel's native schema introspection, confirmed available in this Laravel version — `Schema::hasIndex('transaction_taxes', 'idx_tx_taxes_pk')`, `Schema::getForeignKeys('transaction_taxes')` (find the entry named `fk_tx_taxes_pk`, read its `on_delete` field), `Schema::getColumns('transaction_taxes')` (find the `transaction_pk` entry, read its `nullable` field). Verify the exact return shape of `getForeignKeys()`/`getColumns()` yourself against this Laravel version's source (`vendor/laravel/framework/src/Illuminate/Database/Schema/Builder.php` and its grammar/processor classes) rather than assuming a shape — these methods' return array keys are worth confirming empirically (e.g. via `dd()` in tinker against the real `transaction_taxes` table) before writing the parsing logic.

2. **New migration**: add a nullable `json` column to `tax_backfill_runs` — call it `preflight_checks` — to hold the structured result above. Guard with the existing `Schema::hasColumn` idiom this repo's migrations already use.

3. **New `TaxBackfillRun` status**: `STATUS_PREFLIGHT_FAILED`, alongside the existing five (`running`/`completed`/`interrupted`/`failed`/`stopped`).

4. **Wiring into `apply()`**: immediately after creating the `TaxBackfillRun` row (`status = running`), run the pre-flight check, save its full result into the new `preflight_checks` column via one `$run->update([...])` call. If `passed` is false: set `status = STATUS_PREFLIGHT_FAILED`, `completed_at = now()`, and **return the run immediately** — do not build or execute the `chunkById()` query at all. `scanned_count` and every other counter stay at `0`. If `passed` is true: proceed exactly as `apply()` already does today, unchanged.

5. **CLI surface** (`app/Console/Commands/BackfillTransactionTaxes.php`): add a fourth custom exit code, e.g. `EXIT_PREFLIGHT_FAILED = 4`, to the existing `exitCodeFor()` match. Extend `buildResult()`'s output to include the `preflight_checks` facts (when present — `null`/absent for dry-run, which never runs this check) so an operator can see exactly what was checked and what the recorded facts were, not just a bare pass/fail. Update the `render()` human output to print the pre-flight facts plainly when a run has them, especially prominently when the run failed pre-flight (this is the one output path where "loud failure" matters as much as Slice 5's verification oracle did).

## Test plan

- **Pass case**: run `apply()` (or the CLI with `--apply --day=...`) against the schema as it exists today (which does have both the index and the FK, per the confirmed migration) — pre-flight passes, run proceeds and completes normally, `preflight_checks` on the run record shows `index_present: true, fk_present: true, fk_on_delete_action: 'cascade', transaction_pk_nullable: true`.
- **Forced failure — missing index**: in a test, drop `idx_tx_taxes_pk` via a raw `DB::statement()` call, run `apply()`, assert `STATUS_PREFLIGHT_FAILED`, zero transactions scanned (no `TaxBackfillRecord` rows, no `transaction_taxes` writes), `preflight_checks` correctly shows `index_present: false`. **Critical**: restore the index in a `finally` block that runs even if the assertion fails, so this test can't leave the shared test database in a broken state for every test that runs after it (this repo has a known, separately-tracked `RefreshDatabase` isolation bug — `tests/TestCase.php:38`, logged in `tasks.md`'s Backlog — so you cannot rely on automatic per-test schema rollback here; this restoration must be done explicitly and verified to have succeeded before the test finishes).
- **Forced failure — missing FK**: same pattern, dropping `fk_tx_taxes_pk` instead, with the same guaranteed restoration.
- **CLI-level test**: `--apply --day=...` against a forced-failure schema state exits with the new distinct code, and both human and `--json` output clearly say pre-flight failed and name which check(s) failed.
- **Dry-run unaffected**: confirm `dryRun()` never calls the pre-flight checker and its behavior is completely unchanged (no `preflight_checks` recorded on dry-run `TaxBackfillRun` rows).

## What's explicitly deferred

- Any other pre-flight fact beyond the three named (T097 names exactly these three; don't expand scope to check other tables/columns speculatively).
- T100's operational controls (idle-transaction watchdog, `innodb_lock_wait_timeout`, `SHOW PROCESSLIST` sampling) — a separate, later task, not bundled into this one despite being listed alongside T097 in `tasks.md`'s Implementation Order.
- Archive/delete, aggregate refresh, materiality — unrelated, unchanged.
