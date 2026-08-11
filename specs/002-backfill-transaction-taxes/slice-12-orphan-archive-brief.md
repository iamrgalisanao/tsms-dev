# Slice 12 Architecture Brief — Orphan Archive, Stage 1 of 3 (T066, T067, T068-archive-phase, T072)

**Date**: 2026-08-12 · **Status**: Approved to implement against this brief

## Why this brief exists, and why it's split into three stages

T066-T072 handles the 3,238,180 NULL-keyed orphan rows in `transaction_taxes` (research.md V4): archive them, reconcile inserted replacements against them per day, then delete them. This is this feature's **next major risk boundary** after `--apply` — explicit architecture direction requires it staged the same way apply was (Slice 6 apply-only → Slice 7 controls → Slice 8 CLI wiring), with a hard guardrail: **archive, reconcile, and delete must never be combined into one slice.** Deletion in particular gets its own review/revalidation moment, later.

**This brief covers Stage 1 only: archive.** No reconcile logic (T069, blocked on T068a's measurement anyway), no delete logic (T070/T070a), no CLI phases beyond `archive`. Read-only against `transactions`; writes only to a brand-new archive table. **Nothing in this slice can delete an orphan row, or any row at all.**

## Scope contract

```text
Allowed:
- Migration for a new orphan-archive table (T066).
- app/Services/Backfill/OrphanTaxArchiver.php — chunked, resumable,
  idempotent copy of transaction_taxes WHERE transaction_pk IS NULL
  into the archive table (T067).
- app/Console/Commands/ArchiveOrphanTaxRows.php, --phase=archive ONLY
  for this slice — reject --phase=reconcile/delete outright, mirroring
  how Slice 4 rejected --apply outright before Slice 8 implemented it
  (T068, archive-phase slice of the full command).
- Tests proving the archive is complete and byte-faithful (T072).

Not allowed:
- Any DELETE statement anywhere in this slice's code, against any table.
- T068a's timing-spread measurement, T069's reconciliation logic, T070/
  T070a's deletion logic, or the --phase=reconcile/--phase=delete CLI
  paths — all later stages.
- Any change to TaxBackfillRunner, TaxReconstructionService,
  TaxBackfillPreflightChecker, BackfillTransactionTaxes.php,
  VerifyTaxReconstruction.php — unrelated to this command.
- No change to transaction_taxes's schema itself (that's the separate,
  already-tracked schema-hardening backlog item — NOT NULL enforcement
  comes after this whole feature ships, not during it).
```

## Archive table design (T066)

New table, e.g. `transaction_taxes_orphan_archive` (name it however fits this repo's convention, but be consistent across the migration/model/service):

| Column | Purpose |
|---|---|
| `id` | This table's own PK. |
| `original_id` | The source `transaction_taxes.id` — **unique constraint**, both for restoration fidelity (FR-013: "restoring MUST reproduce pre-run state" means preserving the original row identity) and as the idempotency mechanism (see below). |
| `transaction_pk` | Preserved verbatim (always `NULL` for an orphan, by definition — stored anyway for schema-fidelity, not because it's meaningful). |
| `tax_type` | Preserved verbatim. |
| `amount` | Preserved verbatim. |
| `created_at` | The **original row's own** `created_at` — not the archive operation's timestamp. |
| `updated_at` | The **original row's own** `updated_at`, nullable (mirror whatever nullability the live `transaction_taxes.updated_at` actually has). |
| `archive_run_id` | An identifier for *which invocation* of the archiver wrote this row (a UUID generated once per `ArchiveOrphanTaxRows --apply` invocation is sufficient — this does not need its own dedicated "runs" table the way `TaxBackfillRun` does; a plain string column is enough for T066's stated purpose). |
| `archived_at` | When *this archive row* was written — distinct from the original row's own timestamps. |
| `reconciled_status` | Nullable string. **Stays `NULL` for every row this slice writes** — Stage 2 (reconcile) populates it later. Column exists now so Stage 2 doesn't need its own migration. |
| `reason_code` | Nullable string (e.g. `no_replacement_exists` for the 216 residual rows, eventually). **Stays `NULL` for every row this slice writes** — same reasoning. |

Guard the migration with `Schema::hasTable` (this repo has a documented prior duplicate-migration collision — see T066's own note about `create_ingestion_quarantine_table`). **`down()` MUST be a guarded no-op, not a drop** — once orphans are eventually deleted from the live table (a later stage), this archive becomes the only durable record of every archived row; a migration rollback must never be able to destroy it.

## `OrphanTaxArchiver` design (T067)

- Chunked scan over `transaction_taxes WHERE transaction_pk IS NULL`, ordered by `id` (matches this feature's established chunking convention — `chunkById()`), default chunk size `1000` (matches `cli-contract.md`'s Command 5 example).
- **Idempotency via `insertOrIgnore()` keyed on `original_id`'s unique constraint, not a separate resume-cursor.** This matches the established pattern elsewhere in this feature (idempotency-by-construction via an existence check, not a tracked "resume from here" marker) — re-running the archiver is always safe, and a partially-completed run naturally continues correctly on retry because already-archived rows are silently skipped by the unique constraint, not because anything remembers where it stopped.
- Per-chunk counts: how many rows were newly archived vs. already-archived-and-skipped in that chunk (derivable from comparing the chunk's row count to `insertOrIgnore()`'s affected-row count).
- **Bounded**: each chunk's read+insert is a short operation — no long-lived transaction, no table-wide lock, and this service never touches the `transactions` table at all (FR-005/R9's chunking discipline, same reasoning this feature has applied everywhere else).
- Return a summary (e.g. `{'processed' => int, 'newly_archived' => int, 'already_archived' => int}`) so the CLI command can report progress.

## `ArchiveOrphanTaxRows` command design (T068, archive phase only)

```
transactions:archive-orphan-taxes
    {--phase=archive : Only 'archive' is implemented in this slice. 'reconcile'/'delete' are explicitly rejected — see error message below.}
    {--apply : Persist. Without this flag, dry-run only: report counts, write nothing.}
    {--chunk=1000}
    {--json}
```

No `--day` option in this slice — per `cli-contract.md`, `--day` is required for `reconcile`/`delete` only; `archive` operates over the whole orphan population up front (matches the feature's original "archive ALL orphans, up front, before any per-day loop" pipeline design in `research.md`/`data-model.md`).

- `--phase=reconcile` or `--phase=delete` (or anything other than `archive`, or omitted): reject outright, clear message ("not yet implemented in this build — reconcile/delete are separate, later slices"), zero DB access, matching Slice 4's `--apply` rejection precedent.
- Dry-run (no `--apply`): report total orphan count, how many are already archived, how many would be newly archived — zero writes.
- `--apply`: run `OrphanTaxArchiver`, report the same counts reflecting what actually happened.
- Output: one result structure driving both human and `--json` output, this feature's established pattern (see `BackfillTransactionTaxes.php`/`VerifyTaxReconstruction.php` for the convention — a single `buildResult()`-style method feeding both `render()` paths, not duplicated formatting logic).

## Test plan (T072 — the most important test in this slice)

- **Byte-faithful archive**: archive a set of known orphan rows, then assert — for every one — an archive row exists with `original_id` matching the source `id`, and `tax_type`/`amount`/`created_at`/`updated_at` identical to the source row's own values (not the archive operation's timestamp). This is what "byte-faithful" means here: field-for-field equality against the original row, not just "a row with the same tax_type exists somewhere."
- **Nothing deleted**: after archiving, the original orphan rows still exist, untouched, in `transaction_taxes` — this slice never deletes anything, prove it explicitly (not just by absence of a bug, but as its own assertion).
- **Idempotency**: run the archiver twice over the same orphan population; assert zero duplicate archive rows (unique constraint on `original_id` holds), and the second run's `already_archived` count matches the first run's `newly_archived` count.
- **Resumability**: simulate a partial first run (archive only some of the orphans, e.g. by limiting a test-only chunk count or by directly inserting a partial archive state), then run again to completion; assert every orphan ends up archived exactly once.
- **CLI-level**: dry-run reports correct counts and writes nothing; `--apply` actually archives and reports accurate before/after counts; `--phase=reconcile`/`--phase=delete` are rejected with zero DB access; `--json`/human output agree structurally.
- **Scope discipline**: grep the whole diff for any `DELETE`/`->delete(` statement — confirm zero exist anywhere in this slice's new code.

## What's explicitly deferred to Stage 2 (reconcile) and Stage 3 (delete)

- T068a's timing-spread measurement (blocks T069, not this slice).
- T069's in-situ reconciliation logic and its own CLI phase.
- T070/T070a's deletion logic, its own CLI phase, and the FR-015b residual-specific handling (216 transactions' rows, `reason_code = no_replacement_exists`) — this slice's archive table has the column for this, but nothing in this slice ever writes a non-null value into it.
- T071's "deletion refuses without verified reconciliation" test — there's no deletion to test yet.
- The `--phase=delete --apply` authorization-token mechanism (Architect Q4, `cli-contract.md`) — a later stage's concern.
