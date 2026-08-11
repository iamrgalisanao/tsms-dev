---

description: "Task list for Backfill Transaction Taxes"
---

# Tasks: Backfill Transaction Taxes

**Input**: Design documents from `/specs/002-backfill-transaction-taxes/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md), [data-model.md](data-model.md), [contracts/cli-contract.md](contracts/cli-contract.md), [quickstart.md](quickstart.md)

**Tests**: Test tasks **are** included. This is deliberate, not template default — FR-008 requires validating reconstruction before applying at scale, and `docs/agent-orchestration/workflow.md` classifies this work as high-risk (financial correctness + production-data mutation + multi-tenancy), which mandates targeted tests per slice.

**Organization**: Grouped by user story so each is independently implementable and testable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (US1-US4)

## Confirmed Scope (research.md V1a + V4)

| Fact | Value |
|------|-------|
| Defect window | 2026-06-13 → 2026-08-10 ~10:00 (59 days) |
| Recoverable transactions | 808,891 |
| Unrecoverable (quarantine) | 216 (all 2026-06-13) |
| **Orphan rows to archive + delete** | **3,238,180** (`transaction_pk IS NULL`) |
| Tax rows to insert | ~3.24M |
| Tenants | ~87 |
| Recovery source | `transactions.original_payload` → `$.taxes` |

⚠️ **This feature is NOT insert-only.** V4 confirmed the defective inserts *succeeded* with a NULL key — the data was never lost, only its linkage. The run deletes 3.24M rows. See Phase 0A.

✅ **Gate status (2026-08-11): `ARCHITECTURE_APPROVED` (pass 5) · `IMPACT_ANALYZED` (pass 7) · `BASELINE_RECORDED` · `READY_TO_IMPLEMENT`, all done.** All Phase 0B stakeholder gates (S1, S2, S3, S4, S5, S6) are decided as of 2026-08-11 — see `stakeholder-request-for-input.md`. Remaining work under T084/T086/T088a-1/T088b is carrying those decisions into the implementation/audit trail, not further stakeholder input.

✅ **Finance sign-off, re-confirmed 2026-08-11 (S1).** The 2026-08-10 sign-off was withdrawn (it rested on a false claim that the payload fallback covered `other_tax`); fresh sign-off obtained against the corrected statement, approved as controlled data remediation (spec.md).

⚠️ **Insert-first ordering.** Archive → insert → reconcile in situ → delete once verified. **Revised 2026-08-11**: 2026-06-13's unrecoverable orphans are archived (they are the only surviving record of those 216 transactions' tax lines) **and then deleted from the live table**, same as every other day, once the archive write and residual count are both independently verified. No day carries a permanent live exception anymore.

✅ **Gate 0 cleared**: `ARCHITECTURE_APPROVED` ✅, `IMPACT_ANALYZED` ✅, `BASELINE_RECORDED` ✅, and `READY_TO_IMPLEMENT` ✅ (all six stakeholder gates S1-S6 decided 2026-08-11) are all emitted. Numbered tasks may now begin. **Staging schema confirmation is NOT a numbered task** — it sits in the pre-gate sequence per `workflow.md` (Architecture Review → Baseline → Slice Loop), which is why the former T004 was removed from Phase 1. Baseline recording is done — see `baseline.md`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Establish the feature's code locations.

> **Pre-gate (NOT numbered tasks)**: record the test baseline per `prompt-library.md` item 4 into `baseline.md`, and confirm on staging that `transactions.original_payload`, `transaction_taxes.transaction_pk`, `idx_tx_taxes_pk`, `fk_tx_taxes_pk` (+ its `ON DELETE` action) and column nullability all exist. These precede Gate 0 and cannot be numbered tasks without creating a circular dependency.

- [ ] T001 Create `app/Services/Backfill/` directory and confirm PSR-4 autoload resolves it (no composer.json change expected)
- [ ] T003 [P] Verify `./vendor/bin/pint --test` is clean on the paths this feature will touch, so new formatting failures are attributable

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Reconstruction logic, audit/progress persistence, and quarantine — all required before any story can run. **No tax rows are written in this phase.**

### Reconstruction core

- [ ] T005 Create `app/Services/Backfill/TaxReconstructionService.php` — pure function: given a transaction row, parse `original_payload`, extract `$.taxes`, return the candidate tax rows. No DB writes, no side effects, fully unit-testable
- [ ] T006 In `TaxReconstructionService`, mirror `TransactionIngestService::insertTaxes()` validation exactly — skip rows with missing/empty `tax_type` or null `amount`, and log rather than throw (research R5)
- [ ] T007 In `TaxReconstructionService`, preserve submitted `tax_type` values **verbatim** — no alias normalization, no case folding (data-model.md; Track B owns that decision)
- [ ] T008 In `TaxReconstructionService`, implement the R3 cross-check: compare reconstructed VAT / vatable / SC-VAT-exempt totals against `transactions.vat_amount` / `vatable_sales` / `sc_vat_exempt_sales`; return a mismatch verdict rather than writing
- [ ] T009 [P] Unit tests in `tests/Unit/Services/Backfill/TaxReconstructionServiceTest.php` — well-formed payload, missing `$.taxes`, malformed JSON, null/empty `tax_type`, non-numeric `amount`, cross-check mismatch, and a payload with >4 tax rows

### Audit, progress, quarantine

- [ ] T010 **DECIDED 2026-08-11: dedicated tables, not `SubmissionEvent` reuse** (see data-model.md's "Audit storage decision" and T045). Create migrations in `database/migrations/` for two tables per [data-model.md](data-model.md)'s finalized schema: a run table (run id, window start/end, mode, counters, status, operator/context, started/completed timestamps) and a row table (run id FK, transaction_pk, tenant id, reconstructed tax rows, prior state, outcome, reason code, archive/reconciliation references, timestamps). Must be idempotent (guard with `Schema::hasTable`), matching this repo's migration conventions
- [ ] T011 Create `app/Models/TaxBackfillRecord.php` (and run model if separate) with `$fillable` explicitly listing every writable column — the original defect was a silently-discarded non-fillable attribute, so this is a correctness requirement, not boilerplate
- [ ] T012 Implement outcome states `applied | skipped_existing | quarantined | failed` with a required reason string on `quarantined` and `failed`
- [ ] T013 Represent the 216 unrecoverable rows as `quarantined` with a machine-readable reason (`missing_payload`); they must be recorded, never silently skipped (research V1a). **Extended 2026-08-11**: `quarantined` needs a **second**, distinct reason code — `cross_check_mismatch` — for transactions whose payload exists and parses but fails T008's R3 cross-check (reconstructed values disagree with the transaction's own stored columns). This is a materially different failure mode from "no payload at all" and must be distinguishable in the audit trail: `missing_payload` = no-match (unrecoverable), `cross_check_mismatch` = ambiguous-match (recoverable-looking but suspect, needs human review before ever being trusted)
- [ ] T014 [P] Add a `TaxBackfillRecord` factory in `database/factories/` for use by feature tests
- [ ] T015 [P] Feature test in `tests/Feature/` asserting audit rows are written in dry-run mode with outcome projections and **zero** rows in `transaction_taxes`

### Safe write path

- [x] T016 **Slice 3, dry-run scope only (2026-08-11).** Created `app/Services/Backfill/TaxBackfillRunner.php` — chunked cursor over `transactions` ordered by `id`, delegating reconstruction to `TaxReconstructionService`; one short transaction per chunk, never a window-wide transaction (research R9). `writing via DeadlockRetryService` is **not yet done** — that's the real `--apply` write path, deliberately deferred to a later slice alongside the CLI command; `dryRun()` never writes to `transaction_taxes`. **Noted at Slice 2 drift revalidation (2026-08-11)**: snapshot `TaxBackfillRecord.tenant_id` from the transaction at record-creation time and accept it as a point-in-time denormalization — `Transaction::booted()`'s `saving` hook (`app/Models/Transaction.php:661-665`) supports post-creation tenant reassignment, so this audit field can in principle go stale if a transaction's tenant is ever reassigned after backfill. Exposure is expected to be small (historical/closed window) but is an accepted trade-off, not a re-derivation requirement — don't build live re-sync for this. **Noted at Slice 3 drift revalidation (2026-08-11)**: each chunk's audit-record writes are wrapped in one `DB::transaction()` (per R9); if a failure escapes per-transaction handling mid-chunk, that wrapper rolls back the chunk's already-written `TaxBackfillRecord` rows too, reproducing at chunk granularity the same "one bad row shouldn't erase good rows" problem the per-transaction try/catch exists to solve at row granularity. Not fixed in this slice (no `transaction_taxes` writes exist yet to protect, so the risk is audit-completeness only, not data corruption) — revisit before resumability logic (R6, T032) is built assuming a chunk's audit rows are always all-or-nothing-complete.

---

## Phase 3: User Story 1 — Corrected tax figures in reports (Priority: P1) 🎯 MVP

**Goal**: Every recoverable defect-window transaction has accurate `transaction_taxes` rows, so tenant finance reports for the window are correct.

**Independent test**: Pick any tenant and any date in the window; that tenant's finance report shows non-zero tax totals reconcilable against the transaction's own stored values.

### Dry-run and reconciliation (must land before any write path is enabled)

- [ ] T017 [US1] Create `app/Console/Commands/BackfillTransactionTaxes.php` with the signature in [contracts/cli-contract.md](contracts/cli-contract.md) — `--from`, `--to`, `--tenant`, `--apply`, `--chunk`, `--limit`, `--json`. **Noted at Slice 3 drift revalidation (2026-08-11)**: `cli-contract.md` specifies `--tenant` as repeatable (multiple tenant ids per invocation), but `TaxBackfillRunner::dryRun()` (T016) only accepts a single `?int $tenantId`. Decide explicitly here whether this command calls `dryRun()` once per tenant (producing N `TaxBackfillRun` rows per invocation) or `TaxBackfillRunner` needs to grow multi-tenant support — don't let this get discovered mid-implementation
- [ ] T018 [US1] Implement pre-flight validation (required columns present, window parseable, `--to` after `--from`); return `FAILURE` before any mutation
- [ ] T019 [US1] Implement **dry-run as the default path** — omitting `--apply` performs zero writes to `transaction_taxes`; classify every scanned transaction into reconstructable / already-present / quarantined / failed
- [ ] T020 [US1] Emit dry-run reconciliation counts overall and per tenant, plus per-day totals so output can be diffed against the V1a table in [research.md](research.md)
- [ ] T021 [US1] Implement `--json` output with the same data structurally, for capture into the run record
- [ ] T022 [P] [US1] Feature test in `tests/Feature/` asserting dry-run writes zero tax rows and that counts match a seeded fixture exactly

### Verification command (the safety gate)

- [ ] T023 [US1] Create `app/Console/Commands/VerifyTaxReconstruction.php` — replay `TaxReconstructionService` against **post-fix** transactions (2026-08-10 ~10:00+) that already have correct tax rows, diffing `tax_type` and `amount`
- [ ] T024 [US1] Report any divergence with transaction id and both value sets; exit non-zero when divergences exist
- [ ] T025 [P] [US1] Feature test proving the verifier **fails** on deliberately corrupted reconstruction — a verifier that cannot fail is worthless as a gate

### Idempotent apply path

- [x] T026 **Slice 6 (2026-08-11).** Implemented `TaxBackfillRunner::apply(Carbon $day, ...)` per [slice-6-apply-brief.md](slice-6-apply-brief.md): insert only where the transaction currently has **zero linked** (`transaction_pk` non-null) tax rows (FR-003, FR-004). NULL-keyed orphans are not 'existing rows' for this predicate. Under insert-first they are still **present** at insert time (T070 deletes them afterwards), so the predicate must test for linked rows specifically. **`created_at` on every inserted row MUST be the parent transaction's own `created_at` — never `now()`** (T068a) — implemented in `insertTaxRows()`. Day-scoped only (single `Carbon $day`, no multi-day path exists). Wiring `--apply` into the CLI (`BackfillTransactionTaxes.php`) remains deliberately unimplemented, per the brief — a separate follow-up task, not yet numbered.
  - **Noted at Slice 5 drift revalidation (2026-08-11)**: whoever wires the human/CI gate that enforces "`verify-tax-reconstruction` must report zero divergences before `--apply` is ever run" (cli-contract.md Command 2) must inspect that command's `checked_count`/`candidate_pool_size` JSON fields, not just its exit code — exit 0 only guarantees a non-empty pool and zero divergences, not that a *meaningfully large* sample was actually checked. A defaults-only run early in a fresh post-fix window could exit 0 having checked very few transactions.
  - **Noted at Slice 6 drift revalidation (2026-08-11)**: `TaxBackfillRunner::apply()`'s counter increments (`reconstructed_count` etc.) can become overstated **in the returned PHP object only** (never in the persisted `tax_backfill_runs` row) if a `DeadlockRetryService` retry-then-succeed sequence occurs on the increment statement — Eloquent's `Model::increment()` mutates the in-memory attribute before the query, and only resyncs it on success, so a failed-then-retried attempt leaves a stale base value the next successful increment computes from. Harmless today (nothing reads `$run`'s counters after `apply()` returns), but whoever wires `--apply` into the CLI and reports these counters (mirroring Slice 4's `buildResult()`) MUST either call `$run->refresh()` unconditionally after `apply()` returns, or re-fetch the run fresh before reporting — do not trust the returned object's in-memory counter values directly.
  - **Noted at Slice 6 drift revalidation (2026-08-11)**: the `taxes()->exists()` check and the later insert are two separate, non-atomic operations — there is no unique constraint on `transaction_taxes (transaction_pk, tax_type)` at the DB level, only an index/FK. Inert today given this feature's synchronous, single-process design (no concurrent `apply()` invocations expected), but the "zero linked rows" invariant this whole feature depends on is enforced by a time-of-check, not time-of-insert, guarantee. Worth a unique constraint if this ever runs concurrently or gets queued.
- [ ] T027 [US1] Assert `transaction_pk` is non-null immediately before every insert — the FK permits NULL, which would silently orphan rows (data-model.md)
- [ ] T028 [US1] The backfill command MUST never `UPDATE` or `DELETE` any **linked** tax row (enforced in code, asserted in tests). Orphan deletion belongs solely to the archive command (T070) and must not be reachable from this path
- [ ] T029 [US1] Route quarantined and failed transactions to audit records without writing tax rows, and continue the run rather than aborting the batch

### Throttling, chunking, resumability

- [ ] T030 [US1] Implement `--chunk` (default 500) with a short transaction per chunk; never hold a transaction across chunks (research R9)
- [ ] T031 [US1] Add inter-chunk throttle (configurable sleep/rate cap) so ~3.24M inserts cannot saturate the DB; default conservative
- [ ] T032 [US1] Implement resume — a re-invocation over the same window continues safely; combined with T026 this is idempotent by construction
- [ ] T033 [US1] Add a kill switch (config flag or sentinel file) checked between chunks, so an operator can stop a running backfill without `kill -9`
- [x] T034 **Slice 6 (2026-08-11).** Feature test: run `apply()` twice over the same day; assert **zero** duplicate rows and all second-pass outcomes are `skipped_existing` (FR-004) — `test_apply_run_twice_over_the_same_day_converges_to_skipped_existing_with_zero_duplicates`
- [x] T035 **Slice 6 (2026-08-11).** Feature test: a transaction with pre-existing tax rows (simulating prior manual correction) is left untouched (FR-003) — `test_apply_never_touches_a_transaction_with_a_pre_existing_linked_tax_row`

---

## Phase 4: User Story 2 — Compliance exports reflect corrected data (Priority: P2)

**Goal**: A regenerated BIR/CSMR-style export for the window shows complete tax figures.

**Independent test**: Regenerate an export for an in-window period after backfill; every affected tenant's tax lines are populated and internally consistent.

- [ ] T036 [US2] Identify the export/report code paths in `app/Services/Reports/` and `app/Services/Security/` that read tax data, and confirm each reads from `transaction_taxes` (not a cached aggregate) — document findings in the feature dir
- [ ] T037 [US2] Determine and document the aggregate-refresh sequence after backfill: `reports:refresh-daily-transaction-summaries` and `reporting:refresh`, scoped to the window
- [ ] T039 [US2] Add regeneration of window-scoped aggregates as an explicit, separately-invoked step — never automatic as a side effect of the backfill run
- [ ] T040 [P] [US2] Feature test: given corrected tax rows, a regenerated daily summary reflects the corrected totals

---

## Phase 5: User Story 3 — Auditable record of corrections (Priority: P3)

**Goal**: An internal user can answer "what did we change for this transaction, when, and under which run?"

**Independent test**: Pick any corrected transaction; retrieve its correction record showing run, timestamp, and resulting tax rows.

- [ ] T041 [US3] Create `app/Console/Commands/TaxBackfillShow.php` per Command 4 in [contracts/cli-contract.md](contracts/cli-contract.md) — retrieve correction history for a transaction, following the `IngestionQuarantineShow` precedent
- [ ] T042 [US3] Create a list/inspect surface for quarantined rows following `IngestionQuarantineList`, so the 216 unrecoverable rows are reviewable rather than buried
- [ ] T043 [US3] Ensure every applied correction records prior state (empty) and resulting rows, making the no-overwrite guarantee auditable after the fact
- [ ] T044 [P] [US3] Feature test: after an applied run, each corrected transaction has exactly one retrievable audit record with correct before/after
- [ ] T045 [P] [US3] **DECIDED 2026-08-11**: the dedicated backfill audit table (T010) is the sole source of truth for per-correction records — `SubmissionEvent` is not emitted per correction. `SubmissionEvent` may optionally receive one high-level summary event per *completed run* (not per row); implementing that summary emission, if wanted, is this task's remaining work. See data-model.md's "Audit storage decision"

---

## Phase 6: User Story 4 — Materially-affected tenants identified (Priority: P3)

**Goal**: Produce a defensible list of tenants whose corrected totals crossed the materiality bar.

**Independent test**: The list correctly includes large-correction tenants and excludes trivial ones, verifiable against per-tenant before/after totals.

- [ ] T046 [US4] Create `app/Console/Commands/TaxBackfillMateriality.php` per the CLI contract — `--run`, `--threshold-amount=500`, `--threshold-percent=1`, `--json`
- [ ] T047 [US4] Compute per-(tenant, reporting month) before/after tax totals from the persisted audit records only, so the result is reproducible without re-querying mutated state (SC-006)
- [ ] T048 [US4] Apply the FR-009a rule: flag when the change is ≥ PHP 500 **OR** ≥ 1% of the month's tax total — whichever triggers first (deliberately inclusive)
- [ ] T049 [US4] Make thresholds configurable so finance can tune them without a code change (spec Assumptions)
- [ ] T050 [US4] Output the list only — **do not send notifications**. Dispatch is a separate human-approved step (FR-009b)
- [ ] T051 [P] [US4] Unit test the threshold rule at boundaries: just under both, exactly PHP 500, exactly 1%, and over both

---

## Phase 7: Polish, Operational Safety & Handoff

**Purpose**: Rollback, rehearsal, and the finance/compliance handoff. **These are not optional cleanup — T053-T057 gate the real run.**

### Rollback and containment

- [ ] T052 Document and script two-part rollback in `specs/002-backfill-transaction-taxes/rollback.md`: (a) delete rows attributable to the run via audit records, **and** (b) restore orphans from the archive (FR-013). Note that `daily_transaction_summaries` merges with `max()` so aggregates are monotonic — only a post-rollback refresh restores prior figures (Architect F11)
- [ ] T052a Inventory and pause/account for scheduled jobs that can interact with a live run (impact review, 2026-08-10), none currently mentioned elsewhere in this feature: `reports:refresh-daily-transaction-summaries --days=2` (every 15 min — writes `report_refresh_states`, which FR-012a's source-label pin depends on being stable between snapshot and materiality computation); `transactions-prune` (hourly — deletes `FAILED` transactions older than 14 days, cascading to linked tax rows via `ON DELETE CASCADE`, continuously refed by `transactions-watchdog` flipping `PENDING → FAILED` every 5 min); `tsms:reconcile-intake` (**plain, no flag — `everyMinute()`**, `routes/console.php:25`, the highest-frequency job touching the intake↔transactions relationship, ~1,400 runs across a full-window backfill) and its `--repair-missing` variant (daily 23:00 — could recreate a transaction row with a fresh id and no orphan correspondence). Recommend pausing the pruner/watchdog pair for the maintenance window and capturing a transaction-count census at run start/end regardless. **Noted at Slice 2 drift revalidation (2026-08-11)**: `transactions-prune`'s bulk delete is now also a mechanical FK-violation risk, not just a race condition — `tax_backfill_records.transaction_pk` is a RESTRICT FK straight onto `transactions.id` (T010), so if that job's `WHERE` batch ever includes a transaction that already has a `TaxBackfillRecord` row, the **entire bulk `DELETE` statement fails** for that batch on the FK violation, not just the one row. Backfilled transactions are expected to be `VALID` not `FAILED`, so real overlap should be rare, but pausing the pruner for the maintenance window (already recommended above) closes this too — call it out explicitly in the runbook (T064) as a reason, not just live-ingestion coexistence
- [ ] T053 Define the containment plan: kill-switch procedure (T033), how to identify a partially-completed run, and how to resume vs. roll back
- [ ] T054 Require a verified `transaction_taxes` backup before any `--apply` run over the full window; document the exact backup and restore commands

### Rehearsal before the real run

- [ ] T055 Rehearse the full sequence on a **restored snapshot of staging** (never the live DB — staging carries real pilot-tenant data): dry run → verify → pilot tenant → idempotency re-run → full apply → aggregate refresh. Record timings and peak DB load
- [ ] T056 From rehearsal timings, set the live-run chunk size and throttle, and estimate total runtime for ~809K transactions / ~3.24M inserts. Obtain explicit user authorization before the live run — it mutates real tenant financial data
- [ ] T057 Confirm the live-ingestion coexistence plan: backfill must not dispatch onto `transaction-intake:s{N}` / `transaction-processing:s{N}` (research R9), and must run while the four dashboard endpoints remain stubbed — re-enabling them concurrently would recreate the 2026-08-10 pile-up conditions

### Post-backfill validation

- [ ] T058 Validate coverage by **date**: per-day `with_tax` ≈ `total` across the window, matching the shape of the V1a table (quickstart Step 6)
- [ ] T059 [P] Validate by **tenant**: all ~87 affected tenants show corrected totals; none left at zero
- [ ] T060 [P] Validate by **tax type**: the distribution of reconstructed `tax_type` values is consistent with the post-fix period — a skew indicates a reconstruction bug
- [ ] T061 [P] Validate by **totals**: reconstructed VAT totals reconcile against `transactions.vat_amount` sums per tenant/month (research R3 cross-check applied in aggregate)
- [ ] T062 Confirm zero duplicates post-run: group `transaction_taxes` by `(transaction_pk, tax_type)` having count > 1 returns empty (SC-002)

### Documentation and handoff

- [ ] T063 [P] Write the finance/compliance handoff note: what was corrected, the window, per-tenant materiality list, and which reports/exports were regenerated. **Must state explicitly that whether to re-file with BIR or any authority is finance/compliance's decision, not this feature's** (FR-010a)
- [ ] T064 [P] Add an operational runbook to `docs/` following the existing runbook conventions (e.g. `docs/INGESTION_QUARANTINE_README.md`), covering all three commands, the rehearsal sequence, and rollback
- [ ] T065 Run Documentation Sync per `workflow.md` before commit-group prep — reconcile implementation against spec, plan, research, and runbooks

---

## Phase 0A: AMENDMENT — Orphan handling, baselines & gate fixes (added 2026-08-10)

**Why**: the Architect gate returned `ARCHITECTURE_NOT_APPROVED`. V4 then confirmed **3,238,180 NULL-keyed orphan rows** — the data was never lost, only its linkage. These tasks are numbered T066+ for traceability, but **execution order is governed by the Implementation Order below, not by ID**. Several run *before* existing Phase 1-3 tasks.

⚠️ **The insert-only invariant is void.** This feature now deletes 3.24M rows. Safety rests on archive-before-delete, verify-before-delete, and chunked deletes strictly predicated on `transaction_pk IS NULL`.

### Orphan archive and deletion (runs before any insert)

- [ ] T066 Create migration for an orphan archive table preserving `id`, `tax_type`, `amount`, `created_at`, `updated_at`, plus archive run id and archived-at (FR-013), **plus a nullable reconciled/residual status column and a nullable reason-code column** (FR-013 extended / FR-015b — required so T070a has somewhere to record `no_replacement_exists`; found missing in 2026-08-11 drift revalidation). MUST guard with `Schema::hasTable` — this repo has already shipped one duplicate-migration collision (`create_ingestion_quarantine_table` exists twice: `2025_11_13_000000` and `2026_06_14_000001`). The archive table's `down()` MUST be a guarded no-op, not a drop — once orphans are deleted from the live table (all days, including 2026-06-13, per revised FR-015b), this archive is the **only** durable record of every archived row, not just the 216's
- [ ] T067 Create `app/Services/Backfill/OrphanTaxArchiver.php` — chunked copy of `transaction_taxes WHERE transaction_pk IS NULL` into the archive, resumable, with per-chunk counts
- [ ] T068 Create `app/Console/Commands/ArchiveOrphanTaxRows.php` — dry-run by default, `--apply`, `--chunk`, `--json`; subcommands/flags for archive, verify, and delete phases (extend [contracts/cli-contract.md](contracts/cli-contract.md)). **Noted at Slice 2 drift revalidation (2026-08-11)**: `tax_backfill_records.archive_reference` (T010) is a placeholder shaped for *per-transaction* attribution ("links to the orphan-archive row(s) this row's correction corresponds to"), but T069/T070's reconciliation and deletion are explicitly **day-level, not per-transaction** ("no per-transaction attribution is attempted for either, it is impossible — research.md V4"). When this task actually writes to `archive_reference`, it will most likely hold a day/run-level reconciliation reference rather than transaction-specific orphan-row IDs — decide and document that granularity explicitly here rather than assuming the column name's phrasing dictates the shape
- [ ] T068a **BLOCKING, precedes T069.** Run research.md V5's measurement (`TIMESTAMPDIFF(SECOND, t.created_at, tt.created_at)`, filtered on `tt.created_at` — not `t.created_at`, which is provider-supplied and unvalidated — over post-fix linked rows). If the distribution is ~0 everywhere, FR-014's existing "exactly" wording stands, sourced from the parent's `created_at`. **If a non-trivial spread exists, FR-014 itself (spec.md) MUST be amended with the measured tolerance — this is a spec change, not a T069 implementation choice.** Do not implement T069 before this returns
- [ ] T069 Implement the FR-014 **in-situ reconciliation** (insert-first): after reconstructed rows are inserted — with `created_at` set to **the parent transaction's `created_at`**, never `now()` (T068a) — compare them against the still-present orphans per **(`created_at` [second or measured tolerance], `tax_type`, `amount`) multiset**, evaluated **per day**. Mismatch halts before any other day is touched. Proves *content*, not attribution — attribution is carried by T023's post-fix oracle alone, and both must pass
- [ ] T070 Implement chunked, **per-day** deletion (FR-015) with predicate strictly `transaction_pk IS NULL`, applied **uniformly to every day in the window, including 2026-06-13** (revised 2026-08-11 — no day-level exception). Every orphan for a day whose reconciliation has passed is deleted, whether it belonged to a reconstructed transaction (reconciled set) or had no replacement (residual set, T070a) — no per-transaction attribution is attempted for either (it is impossible, research.md V4). Bound each chunk by archived id range so it is replayable; assert affected-row counts per chunk; never a single bulk `DELETE`
- [ ] T070a **Archive, verify, then delete ALL of 2026-06-13's orphans wholesale** (FR-015b, revised 2026-08-11 — reverses the original "retain, never delete" decision). Mark each archived row with reason `no_replacement_exists` and surface in the quarantine report **before** deletion, so the evidence trail exists independent of the live table. Deletion of this residual is gated on two conditions, same as the reconciled set: the residual count verifies exactly 216 transactions' worth of rows for that day (FR-014), and the archive write verifies successful (FR-013). A failure on either blocks deletion — archive-then-delete, never delete-without-verified-archive. No rows are over-retained for the 9 reconstructable transactions on this day; they follow the normal reconciled-set path
- [ ] T071 [P] Feature test: deletion refuses to run when T069 reconciliation has not passed for that day, never touches a row with non-null `transaction_pk`, and — for 2026-06-13 specifically — refuses to delete the residual if archive-write verification has not independently succeeded (the uniform guardrail replacing the old "never touches 2026-06-13" exception)
- [ ] T072 [P] Feature test: archive is complete and byte-faithful before any delete occurs; restoring from archive reproduces the pre-run table state

### Pre-backfill baselines (unrecoverable once the run starts)

- [ ] T073 Create `app/Console/Commands/SnapshotPreBackfillAggregates.php` — capture per-(tenant, reporting month) **rendered** aggregate totals via the actual report path, before any mutation (FR-012). This is the only defensible materiality `before` and cannot be recovered later
- [ ] T074 Persist the snapshot durably and make it the sole `before` source for T047's materiality computation — **replaces the `before = 0` assumption**, which would have flagged nearly every tenant
- [ ] T075 [P] Capture a pre-run baseline of the T062 duplicate-check query, so the post-run comparison is against that baseline rather than against zero (Architect F8)

### Correctness and safety fixes from the gate

- [ ] T076 Replace T062's duplicate check: verify run-scoped inserted row ids equal the sum of audit-record reconstructed counts, and that the count of `transaction_pk IS NULL` rows in `transaction_taxes` is **zero** after the full run completes (revised 2026-08-11 — FR-015b now deletes the 216's orphans too, so "zero" is the correct post-run assertion, not an exception). The `GROUP BY (transaction_pk, tax_type)` check is demoted to a secondary signal compared against T075's baseline — payloads may legitimately repeat a `tax_type` (Architect F8)
- [ ] T077 **Record** the aggregating connection's resolved `@@server_id`/`DATABASE()` values in the run record (not merely assert equality against config) — `config/database.php:59-75`'s `reporting` connection falls back to the primary's env vars per-field, and `.env.example` ships those blank, so in an environment that hasn't set them an equality *assertion* passes vacuously without proving anything. Implement a `MASTER_POS_WAIT` gate **only if** the recorded values differ from the primary's. **Supersedes T038** — both refresh commands aggregate on the primary, so the original replica-lag blocker was aimed at a hazard that largely does not exist (Architect F4)
- [ ] T078 Batch the insert path: resolve parent data from rows already loaded in the chunk and use multi-row inserts, **setting each row's `created_at` from its own parent transaction's `created_at`** (T068a) — batching must not collapse this to a single shared timestamp per batch. Reusing `insertTaxes()`/`attachTransactionReference()` verbatim would issue ~6.5M statements — including ~3.24M single-row SELECTs against the table with the 2026-08-10 outage history (Architect F5, Q8)
- [ ] T079 Implement the Q4 authorization boundary as an **enforced** mechanism (explicit confirmation token / signed approval recorded in the run record), not documentation. T056 currently only says "obtain authorization" (Architect F10e)
- [ ] T080 [P] Add a tenant-isolation proof: assert that per-tenant counts, materiality figures, and validation outputs partition exactly — no transaction attributed to a tenant other than its own `transactions.tenant_id`. T059 checks completeness, which is not isolation (Architect Q6/F10c)
- [ ] T081 [P] Regression test: assert `TransactionTax::$fillable` contains `transaction_pk`, and that no code path calls `TransactionTax::create()` with a `transaction_id` key — the cheapest permanent guard against the entire defect class (Architect F7)
- [ ] T082 Correct research.md R8, data-model.md, and quickstart Step 7 to name only `reports:refresh-daily-transaction-summaries` as genuinely affected. `RefreshHourlyWindowJob` is a deprecated no-op and `transactions_hourly` derives tax from `transactions` columns (Architect F3) — **partially applied 2026-08-10; verify no residual references**


---

## Phase 0B: Stakeholder gates — ALL DECIDED 2026-08-11

**No longer blocking `READY_TO_IMPLEMENT`.** All six stakeholder decisions (S1-S6, see `stakeholder-request-for-input.md`) are recorded. Architecture approval was granted on pass 5 without them, by design — requiring finance sign-off as a precondition of architecture review would have reproduced the circularity that retired T083. The tasks below remain open only because each one still has a small piece of engineering follow-through (audit-trail capture, or an actual code change) beyond the decision itself.

- [ ] T084 **DECIDED 2026-08-11 (S1)**: finance/PITX re-sign-off obtained against the corrected impact statement (N1) — approved to proceed as a controlled data remediation, not an emergency fix; corrected impact does not change rollout priority; tenant communication is materiality-based only (FR-009a), not a blanket notice. Remaining work for this task: capture this decision in the audit trail (run record / migration comments), same convention as T085
- [ ] T085 **Decision recorded 2026-08-11 (this task's original purpose is fulfilled; remains as an implementation task).** The stakeholder decision on the 216 unrecoverable transactions' orphan rows is: archive (durable, verified, reason `no_replacement_exists`) and then **delete** from the live table, same as every other day — reversing the original "retained live forever" default. See FR-015b, T070a. Remaining work for this task: ensure the decision and its rationale are captured in the implementation's audit trail / migration comments, not just this spec, so a future reader of the code sees why 2026-06-13 has no special case
- [ ] T086 **DECIDED 2026-08-11 (S2)**: `transactions.tax_exempt` (boolean summed as currency) is excluded from exact-match assertions and materiality math (SC-003, FR-009a already state this). Fixing the underlying defect remains out of scope, tracked separately. Remaining work for this task: ensure the exclusion is actually implemented in the SC-003/FR-009a comparison logic, not just documented
- [ ] T087 Implement containment for `backfill:transaction-aggregates --allow-write` (FR-017): it is inert today only because the window has no linked rows, and this feature arms it. Add a guard or window-exclusion, plus a runbook prohibition alongside T057
- [ ] T088a **DECIDED 2026-08-10 — Option 1, allow-list variant.** Implement per D1-D8 in [decision-t088a-other-tax-semantics.md](decision-t088a-other-tax-semantics.md). No longer an open branch
- [ ] T088a-1 **DECIDED 2026-08-11 (S6), supersedes the original external-consumer investigation.** `calculated_net_sales` is the canonical PITX-derived value; `net_amount` becomes a **compatibility alias carrying the same corrected value**, not a second diverging formula. The feature proceeds without waiting on a yes/no answer about `POST /api/v1/transactions/{transaction_id}/refund`'s consumers (`routes/api.php:203` → `TransactionController.php:63`, guarded by `auth:sanctum` + `abilities:transaction:create`) — both fields change together regardless of who reads them. Remaining work for this task: implement `net_amount` as an alias of (or identical calculation to) `calculated_net_sales` in `Transaction.php`, and note the behavior change in release/handoff notes since the endpoint's response values do change
- [ ] T088a-2 Implement the **allow-list** in `app/Models/Transaction.php`: `otherTaxSum()` counts only `OTHER_TAX`/`OTHER-TAX` (D1) and its `sc_vat_exempt_sales` column-fallback is **removed**. Move that deduction into `getNetAmountAttribute()`/`getCalculatedNetSalesAttribute()` as an explicit term — `gross − otherTaxSum() − scVatExemptSales` (D7). Textually align `TransactionValidationService::validateAmountReconciliation():688` — the one method with its own exclusion logic — with the same allow-list (D4, narrowed per T088a-8 — documentation hygiene, not a behavioral fix, since it's unreachable dead code); `validateAmounts():593` needs no equivalent change, it's a bare delegation to `otherTaxSum()` and inherits the fix automatically (optionally update its stale `:592` comment). Do **not** touch `TSMSTransactionRequest`'s commented-out exclusion logic (dead code, see T088a-8) — that's out of scope, logged to Track B (T088c). Remove the now-unnecessary subtract-back workaround (the `else` branch at lines 692-698, body 693-697, immediately following the `:688` loop)
- [ ] T088a-2b Implement D3 observability, specified testably: (i) define the **known-type universe** as an explicit companion list (`VAT`, `VATABLE_SALES`, `SC_VAT_EXEMPT_SALES`, vat-exempt aliases, zero-rated/non-VAT aliases) so recognised-but-excluded types do NOT trigger warnings — otherwise every serialization warns; (ii) map context→mechanism (ingestion → validation warning; backfill → quarantine; accessor → log); (iii) **dedupe/rate-limit** per `tax_type` per request — this helper runs inside `$appends` on every API serialization and would otherwise flood logs and add per-request I/O; (iv) acceptance criterion: an unknown type is surfaced exactly once per request and contributes `0.00` to `other_tax`
- [ ] T088a-6 **Pin the `scVatExemptSales` source for D7 to the `transactions.sc_vat_exempt_sales` COLUMN** (not a row sum). Rationale: window transactions have zero linked rows today, so their current `net_amount` already derives from the column — a column-based term is therefore **exactly neutral** for the backfill population, which is D7's whole purpose. Record the choice explicitly so an implementer does not substitute a row sum
- [ ] T088a-7 Quantify the **alias residual** as an S7-analogue before implementing: `applyTaxColumns()` (`TransactionIngestService.php:356`) populates the column only for `SC_VAT_EXEMPT_SALES`/`VAT_EXEMPT_SALES`/`VATEXEMPT_SALES`, so rows typed `VATEXEMPT`/`EXEMPT`/`VAT-EXEMPT` are deducted today (via the all-non-VAT sum) but would not be under a column-based D7. This affects **out-of-window** transactions that already have linked rows. Measure count, tenants and peso exposure before the fix ships
- [ ] T088a-8 **D4 narrowed, and lower-stakes than originally framed.** `validateAmounts()` and `validateAmountReconciliation()` are both unreachable dead code (T088a-3a's finding) — `validateTransaction()`, their only path into production, never calls either. Only `validateAmountReconciliation()` has exclusion logic of its own to reword textually — `validateAmounts()` merely delegates to `otherTaxSum()` and inherits the fix with no edit needed. This is **pure hygiene with zero runtime risk**, not a behavioral fix, and it does not extend to `TSMSTransactionRequest` — also dead code, its exclusion logic entirely commented out under an explicit "not enforced during ingestion" policy, with the containing class never instantiated since its only caller has no route (deferred to Track B as a documentation-only defect, T088c). The validator's exclusion comment at `:682-683` (excludes `SC_VAT_EXEMPT_SALES` because it "represents non-VAT sales composition, not a tax that reduces net sales") remains good documentation of intent even though the code never executes — keep `validateAmountReconciliation()` textually aligned with the accessor's allow-list for whenever/if this validation path is re-wired, but do not write a runtime-agreement test for it; there is no runtime to test. If a fixture is still wanted for documentation purposes, it must use Reflection (`ReflectionMethod::setAccessible`) since both methods are `private`
- [ ] T088a-5a Record the **allow-list/deny-list asymmetry this fix does not resolve**: post-fix, the model layer uses an allow-list (`OTHER_TAX`/`OTHER-TAX` only), while `RefreshDailyTransactionSummaries.php:120`/`SalesReportDataService.php:223` use a 13-item deny-list that does **not** exclude `OTHER_TAX`, and `FinanceCalculationService::NON_OTHER_TAX_TYPES` uses those 13 **plus** `OTHER_TAX`. For any tax type outside both lists, the model and the aggregate disagree in opposite directions. **Out of scope** per D4 (which only requires the two dead `TransactionValidationService` methods to be textually aligned, not the aggregate-layer SQL/list logic) — but SC-003 compares the aggregate layer against the FR-012 snapshot while the model layer moves independently — state this explicitly so it isn't mistaken for a bug introduced by this feature
- [ ] T088a-5 Confirm the canonical `OTHER_TAX` alias set against Track B (`docs/specs/report-vat-correction-coverage.md`), which owns alias normalization. The allow-list must not fork a second alias vocabulary
- [ ] T088a-3a **This is the first test coverage `otherTaxSum()`/`net_amount`/`calculated_net_sales` have ever had.** Grep confirms zero existing tests reference any of them (impact review, 2026-08-10). Sequence as **characterize-then-flip**: write tests pinning *current* behavior first (so the fix's effect is provably intentional, not accidental), then flip assertions to the corrected values in the same change
- [ ] T088a-3 [P] Regression tests (see T088a-3a for sequencing): the 65.00/58.04/6.96/0.00 fixture must leave `net_amount` at 65.00; the same shape with `OTHER_TAX = 10.00` must yield 55.00; a transaction with non-zero `sc_vat_exempt_sales` must have `net_amount` **unchanged** across the fix (D7 — proving no PHP 13.8M swing). **No runtime validator-agreement assertion** — `validateAmounts()`/`validateAmountReconciliation()` are both unreachable dead code (T088a-8); if a documentation-purposes fixture is wanted for either, it requires `ReflectionMethod::setAccessible` since both are `private`
- [ ] T088a-4 Assess and communicate the pre-backfill blast radius: transactions **outside** the defect window that already have linked rows move from `gross − (VATABLE + SC_VAT_EXEMPT + OTHER + aliases)` to D7's `gross − OTHER − sc_vat_exempt_column`. Dominated by `VATABLE_SALES` no longer being deducted, so `net_amount` **increases** substantially. A correction, but a visible change to already-published values, and it includes the T088a-7 alias residual
- [ ] T088b **DECIDED 2026-08-11 (S4)**: PITX/Finance is confirmed as the formula worksheet's owner, and the supplied PITX computation screenshot is accepted as the source-of-record. Provenance question closed. Remaining work for this task: preserve the screenshot as tracked evidence (commit it, or a faithful transcription, into the repo alongside [other-tax-semantics.md](other-tax-semantics.md)) — the plan must not keep resting on an untracked image even though the ownership question is settled
- [ ] T088c [P] Log the divergence against the PITX formula as a defect for the Track B workstream in `docs/specs/report-vat-correction-coverage.md`, which currently covers alias normalization but **not** the `other_tax` component question. Three implementations to note, with correct status (impact review, 6th pass): `Transaction::otherTaxSum()` — live, being fixed by this feature; `RefreshDailyTransactionSummaries` SQL / `FinanceCalculationService` — live, unrelated to this feature; `TSMSTransactionRequest:153` — **dead** (exclusion logic entirely commented out under an explicit "not enforced during ingestion" policy; the containing class is never instantiated since its only caller, `storeOfficialLegacy()`, has no route). Also flag `TSMSTransactionRequest.php.backup` for cleanup — it carries a second commented-out copy of the same dead logic (`:159-200`, under an older "temporarily disabled / TODO: re-enable" banner) and is a plausible source of future confusion since it reads as provisional rather than settled, though harmless at runtime since `.backup` isn't autoloaded
- [ ] T088 Enumerate the `Transaction::$appends` / `otherTaxSum()` API surface (N4): `net_amount` and `calculated_net_sales` change per-transaction post-backfill, and `TransactionValidationService::validateAmounts()`/`validateAmountReconciliation()` exist but are unreachable dead code (`validateTransaction()`, their only production caller, is a passive no-op) — so no re-validation decision is needed for them. Decide only whether the `$appends` accessors need to be re-validated over the window, and include that decision in — or explicitly exclude it from — the FR-012 snapshot scope

### Required corrections (all must land in the same pass)

- [ ] T094 Scope the aggregate refresh per tenant and/or per day (N8): it currently builds an unbounded `groupBy('transaction_pk')` derived table over all of `transaction_taxes` and wraps ~10⁴ inserts in a single transaction — the same long-transaction shape as the 2026-08-10 outage
- [ ] T096 T008 must implement **defect-era last-wins** semantics with the narrow alias set (N10), not `SUM`. A SUM-based cross-check false-positives — and therefore quarantines — on payloads legitimately carrying two rows of the same type
- [ ] T097 Extend pre-flight (N11) to assert: `idx_tx_taxes_pk` presence (without it every delete chunk is a full scan), `fk_tx_taxes_pk` presence and its `ON DELETE` action, and `transaction_pk` nullability. Record all three in the run record. Note migration `2025_08_13_000013` currently fails silently because NULLs exist — removing them changes that
- [ ] T099 [P] Use `php artisan txn:pk-integrity` (N15) as the pre/post evidence capture for T075/T076 rather than bespoke queries
- [ ] T100 Add operational controls (Architect): idle-transaction watchdog before each phase (refuse to start if a transaction older than 60s exists — the exact condition that turned a slow statement into the 2026-08-10 outage), low `innodb_lock_wait_timeout` with per-chunk retry so the backfill yields to live traffic, and `SHOW PROCESSLIST` sampling captured to the run record rather than watched by a human
- [ ] T101 Stratify the T023 oracle sample by tenant and by day (Architect): 500 uniform samples is thin against 809K transactions
- [ ] T102 Name the mid-run "no tax rows at all" state in the containment plan (T053): reporting-neutral, since orphans contributed nothing, but an operator seeing zero rows will otherwise assume the worst

### Amended Implementation Order

```text
DECIDED, engineering follow-through remains:  other_tax allow-list fix [T088a: DECIDED, T088a-2/-2b/-3]
                                              net_amount/calculated_net_sales alignment [T088a-1: DECIDED S6]
DECIDED, audit-trail/evidence capture remains: finance re-sign-off [T084: DECIDED S1] · FR-016 disposition [T086: DECIDED S2]
                                              formula provenance [T088b: DECIDED S4, screenshot preservation pending]
                                              (216-row decision [T085] resolved 2026-08-11, FR-015b)
  ↓
PRE-GATE (not numbered tasks):   baseline recording · staging schema confirmation
  ↓
GATE 0: ARCHITECTURE_APPROVED ✅ (pass 5) · IMPACT_ANALYZED ✅ (pass 7) · BASELINE_RECORDED ✅ · READY_TO_IMPLEMENT ✅ (all S1-S6 decided 2026-08-11)
  ↓
 1. Doc corrections, retractions, containment    T087, T096, T097, T099, T100-T102
 2. Reconstruction core + tests (defect-era)     T005-T009, T096
 3. Audit/quarantine persistence                 T010-T015
 4. Pre-backfill baselines (IRREVERSIBLE)        T073-T075, T099
 5. Orphan ARCHIVE (all, incl. 06-13)            T066-T068, T072
 6. Dry-run command                              T017-T022
 7. Oracle: post-fix attribution proof           T023-T025, T101
 8. Pre-flight assertions + op controls          T097, T100
 9. PER-DAY LOOP over 2026-06-13 .. 2026-08-09   (FR-014a — NOT phase-wide)
       for each day D:
         9a. INSERT reconstructed rows for D       T026-T029, T078, T034-T035
         9b. RECONCILE in situ vs D's orphans      T068a (once, before loop), T069, T071
         9c. DELETE D's orphans (uniform, all days) T070, T079
             └─ 2026-06-13 residual: archive+verify then delete, same gate  T070a
         halt immediately on mismatch — do NOT advance to D+1
10. Throttle / resume / kill switch (spans loop)  T030-T033, T102
11. Connection assertion → scoped agg refresh    T077, T094, T039-T040
12. Validation incl. isolation + dup rework      T058-T061, T076, T080
13. US3 / US4 / polish / handoff                 T041-T051, T052-T057, T063-T065
```

**Ordering rationale**: within each day, insert (9a) precedes reconcile (9b) precedes delete (9c). Originals survive until their replacement is proven *in place*; a bad insert rolls back without touching the archive; reconciliation compares what was actually written, not what a dry run predicted; and per-day scoping means a systematic defect halts after one day rather than after 3.24M rows.

---

### Backlog (NOT tasks in this feature)

- `docs/specs/vat-reconciliation/*.sql` joins on `tt.transaction_id` (the dropped column) instead of `tt.transaction_pk`. A real pre-existing defect, but **out of scope here** — tracked separately so it is not silently absorbed into this feature.
- **Schema hardening: enforce `transaction_taxes.transaction_pk NOT NULL` (+ FK where possible).** Added 2026-08-11. Migration `2025_08_13_000013_enforce_transaction_pk_children.php` already attempted this and fails silently today because NULLs exist (T097). This feature's revised FR-015b (archive-and-delete every orphan, including the 216's, rather than retaining any live) is what makes `NOT NULL` achievable for the first time — zero permanent orphans remain after this feature completes. Still out of scope here: this feature's job is to reach zero orphans, not to add the constraint. A follow-up ticket should re-attempt the migration once this feature has shipped and been verified stable. **Caveat (2026-08-11 drift revalidation)**: this feature clears the *known* NULL source (the ingestion defect); it does not verify whether `fk_tx_taxes_pk`'s `ON DELETE` action could reintroduce NULLs via `transactions` deletions (T097 leaves this unasserted). The follow-up ticket's pre-work should confirm that before re-attempting the constraint.
- **Repo-wide test-infrastructure defect: `tests/TestCase.php:38` breaks `RefreshDatabase` transaction isolation.** Found during Slice 3 (2026-08-11), confirmed against the Laravel framework source (not just observed behavior): `setUp()` calls `$this->refreshApplication()` immediately after `parent::setUp()`, which is where `RefreshDatabase` opens its per-test transaction. `refreshApplication()` rebuilds the app container and DB connection, orphaning that transaction — so writes made during a test are never rolled back, and rows can leak between test methods within the same run. **Explicitly out of scope for this branch/feature** (per architecture direction, 2026-08-11) — this is a pre-existing, repo-wide defect unrelated to tax backfill, not something to fix as a side effect of this feature's slices. This feature's tests (Slices 1-3) are written to be immune to it — every count-sensitive assertion is scoped to a freshly-created tenant/run id rather than relying on table-wide emptiness — but other suites that assume clean per-test isolation may be silently affected (hidden flakes, or tests that pass only because of leaked state from an earlier test). Needs its own follow-up ticket to fix `TestCase.php` and audit for count-sensitive tests elsewhere that aren't similarly scoped.

---

## Dependencies

```text
Phase 1 (Setup)
  └─> Phase 2 (Foundational) ──────────────┐
        ├─ T005-T009 reconstruction core   │  BLOCKS all stories
        ├─ T010-T015 audit/quarantine      │
        └─ T016 runner skeleton            │
                                           v
        ┌──────────────────────────────────┴───────────────┐
        │                                                  │
   Phase 3 (US1, P1) ── MVP ──> Phase 4 (US2, P2)          │
        │                            │                     │
        │                            v                     │
        └──────────> Phase 5 (US3, P3)  Phase 6 (US4, P3) <┘
                            │                │
                            └────────┬───────┘
                                     v
                          Phase 7 (Polish & Ops)
```

**Hard ordering constraints** (beyond phase order):

- **T023-T025 (verification) must pass before T026 (`--apply`) is ever run against real data.** This is the feature's primary safety gate, not a nicety.
- ~~T038 (replica lag)~~ **DELETED** — both refresh commands aggregate on the primary (Architect F4). Superseded by T077's connection-identity assertion.
- T055-T057 (rehearsal) block the real full-window run.
- US4 (T046-T051) depends on US1 having produced audit records, but its *implementation* can proceed in parallel with US2/US3.

## Parallel Opportunities

- **Phase 2**: T009, T014, T015 are independent files → parallel. T005-T008 are the same file → sequential.
- **Phase 3**: T022, T025, T034, T035 are separate test files → parallel once their subjects exist.
- **Phase 7**: T059, T060, T061 are independent read-only validations → parallel. T063, T064 are separate docs → parallel.
- **Cross-story**: US2, US3, US4 implementations are largely independent once Phase 3 lands.

## Implementation Strategy

**MVP = Phase 1 + Phase 2 + Phase 3 (US1).** That delivers the actual data correction — the reason the feature exists. US2/US3/US4 add compliance regeneration, auditability, and tenant comms on top of correct data.

**Recommended slice order** (per `workflow.md` slice loop, each slice: implement → targeted tests + Pint → Code Reviewer → architect drift revalidation, since this is high-risk):

1. Foundational reconstruction + tests (T005-T009) — pure logic, no risk, highest value to get right first
2. Audit/quarantine persistence (T010-T015)
3. Dry-run command (T017-T022) — still zero writes
4. **Verification command (T023-T025)** — must be green before proceeding
5. Apply path + idempotency (T026-T029, T034-T035)
6. Throttling/resume/kill-switch (T030-T033)
7. Then US2 → US3 → US4
8. Phase 7 rehearsal and validation before any real run

**Stop-and-ask triggers** (per CLAUDE.md autonomous boundaries): audit persistence choice (T045), whether a separate production deployment exists distinct from staging (see Open Questions), and any destructive Git action.

## Open Questions for the Architecture Gate

1. ~~Read-replica lag~~ — **RESOLVED**: no replica gate needed; both refresh commands aggregate on the primary. Superseded by T077.
2. ~~**Audit persistence** (T010, T045) — dedicated table vs. `SubmissionEvent` reuse.~~ **RESOLVED 2026-08-11**: dedicated tables; `SubmissionEvent` gets, at most, one optional per-run summary event. See data-model.md.
3. **Exposing `insertTaxes()` semantics** — it is `protected`; extract a shared writer vs. duplicate carefully (research R5), without destabilizing the live path.
4. **Execution vehicle** (T016, T030-T033) — synchronous command vs. dedicated queue; must not touch live shards.
5. **Void/refund handling** — spec edge case; confirm reconstructed taxes for voided transactions match live semantics.
6. **Quarantine surface depth** (T042) — full `IngestionQuarantine`-style triad vs. a simpler report for just 216 rows.
7. ~~**"Production run" definition**~~ — **RESOLVED 2026-08-10**: staging *is* the live pilot system carrying the affected tenants' real operational data. "Production run" means the real run against staging's live data. No separate production deployment is in scope unless another is later confirmed. Rehearsal (T055) is on a restored snapshot.
