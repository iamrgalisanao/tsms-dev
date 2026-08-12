# Implementation Plan: Backfill Transaction Taxes

**Branch**: `002-backfill-transaction-taxes` | **Date**: 2026-08-10 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/002-backfill-transaction-taxes/spec.md`

## Summary

Repair `transaction_taxes` for a 59-day defect window in which every tax row was written with a NULL foreign key. **Insert-first ordering**: archive all 3,238,180 orphan rows, insert correctly-keyed rows reconstructed from retained payloads, reconcile the new rows against the still-present orphans in situ, then delete each day's orphans once its reconciliation and archive-write verification both pass — **uniformly, including 2026-06-13's unrecoverable orphans** (revised 2026-08-11; the durable archive, not permanent live retention, is now the evidence of record for those 216 transactions). Finally regenerate the aggregates that genuinely change.

⚠️ **BLOCKED on FR-018.** Row-level `other_tax` semantics must be aligned with the PITX business formula before any reconstruction reaches the accessors. `Transaction::otherTaxSum()` counts every non-`VAT` row — including `VATABLE_SALES`, which the PITX formula treats as the *base* of gross, never a deduction. It is inert only because the window has no linked rows; the backfill activates it, collapsing `net_amount` by ~89% on the worked example across 809,107 transactions. See [other-tax-semantics.md](other-tax-semantics.md).

**Revised across five Architect passes (2026-08-10); `ARCHITECTURE_APPROVED` at pass 5.** Two premise-level defects were found early and empirically confirmed:

- **The data was never lost — only its linkage.** The defective inserts *succeeded*, writing `transaction_pk = NULL`. All three original gating verifications joined on `transaction_pk` and were structurally blind to those rows (research.md V4). The insert-only invariant this plan formerly rested on is **void**.
- **Reports were largely correct already.** VAT/vatable/SC-VAT-exempt come from `transactions` columns that were never lost; only `other_tax` depends on this table. The business case is re-baselined in spec.md around source-of-truth integrity rather than "wrong reports".

The enabling discovery from Phase 0: the defective code path **still persisted the full submitted payload** to `transactions.original_payload`, including the `taxes` array. Recovery is therefore *replay of retained original values*, not estimation — which is what makes FR-011's exact-fidelity requirement achievable. Correctness is provable ahead of time by replaying the reconstruction against post-fix transactions, where known-good tax rows already exist as an oracle.

Delivered as dry-run-by-default console tooling following the `LicenseBindingBackfillCommand` precedent — idempotent, resumable, chunked, with a persisted audit record per corrected transaction and a durable archive of every deleted orphan.

## Technical Context

**Language/Version**: PHP 8.2+, Laravel 11

**Primary Dependencies**: Eloquent/DB query builder, Laravel console; existing `TransactionIngestService` (write semantics), `DeadlockRetryService` (safe writes), reporting commands (`RefreshDailyTransactionSummaries`, `ReportingRefreshCommand`)

**Storage**: MySQL. Read: `transactions` (incl. `original_payload`). Write: `transaction_taxes` (delete NULL-keyed orphans + insert correctly-keyed rows), a new backfill audit record, an orphan archive table, and a pre-backfill aggregate snapshot. Recompute: `daily_transaction_summaries` (`other_tax`, and `sc_vat_exempt_sales` for alias variants) — **not** `transactions_hourly`, which derives tax from `transactions` columns and is unaffected (Architect F3).

**Testing**: PHPUnit (`Unit`/`Feature` suites per `phpunit.xml`); local convention `DB_DATABASE=tsms_db_test php artisan test`. Pint for formatting.

**Target Platform**: Linux server (staging `LNX-PTX-POS01`), Redis/Horizon queues, read-replica for reporting

**Project Type**: Backend batch/operational tooling within an existing Laravel monolith

**Performance Goals**: Complete the full window within a maintenance window without any live-ingestion degradation. Throughput is explicitly subordinate to safety — chunked, rate-bounded, abortable.

**Constraints**: No long transactions or table-wide locks on `transactions`/`transaction_taxes` (2026-08-10 outage precedent). **Not insert-only** — the run deletes 3.24M orphan rows, so the safety argument rests on archive-before-delete + verify-before-delete + chunked deletes restricted to `transaction_pk IS NULL`, not on immutability. Idempotent and resumable. Must not dispatch onto live intake/processing shards. Must not normalize `tax_type` aliases (separate Track B concern).

**Scale/Scope**: **Confirmed** (research.md V1a + V4). Additionally: **3,238,180 orphan rows to archive and delete.** Window **2026-06-13 → 2026-08-10 ~10:00** (59 days), ~87 tenants, 811,801 transactions in window. **808,891 to reconstruct** (99.97%), **216 unrecoverable** → quarantine. Estimated **~3.24M tax rows inserted** (≥4 per transaction). Peak day 2026-06-25 at 33,168 transactions.

## Constitution Check

`.specify/memory/constitution.md` is **unpopulated boilerplate** (all `[PRINCIPLE_N_NAME]` placeholders). No project-specific constitutional gates can be evaluated from it.

Governance is instead taken from `CLAUDE.md` + `docs/agent-orchestration/`, which do impose binding gates:

| Gate | Status |
|------|--------|
| Risk tier | **High** — financial/reporting correctness + production-data mutation + multi-tenancy (three separate High-Risk Gates triggers in `workflow.md`) |
| Required sequence | Full diagram: Architecture Review → Baseline → Slice Loop (+ per-slice drift revalidation) → Documentation Sync → Commit-Group Prep |
| Gate 0 | `ARCHITECTURE_APPROVED`, `IMPACT_ANALYZED`, `BASELINE_RECORDED`, `READY_TO_IMPLEMENT` all required before implementation |
| Model routing | Opus for architecture review and code review |
| Remote actions | Push/PR/merge each require explicit user authorization |

**Result**: PASS to proceed to architecture review. **Not** clear to implement — Gate 0 is unsatisfied by design at this stage.

**Gating Verifications**: V1/V1a/V1b confirmed the window and payload recoverability. **V4 (post-gate) overturned a core premise** — 3,238,180 orphan rows exist that all three earlier checks were blind to. V2 moot. Feasibility risk is closed; residual risk is execution-side (3.24M deletes + 3.24M inserts on a table with a proven lock-contention outage history).

**Gate status (2026-08-11): `ARCHITECTURE_APPROVED` (pass 5) · `IMPACT_ANALYZED` (pass 7, 7 review rounds) · `BASELINE_RECORDED` (461 passed / 112 pre-existing failures, none in this feature's scope — see `baseline.md`) · `READY_TO_IMPLEMENT`, all done.** All Phase 0B stakeholder gates (S1-S6, see `stakeholder-request-for-input.md`) are decided. Remaining work is the slice-loop implementation itself, plus the small engineering follow-through each decision still carries (T084, T086, T088a-1, T088b).

## Project Structure

### Documentation (this feature)

```text
specs/002-backfill-transaction-taxes/
├── spec.md              # Feature specification (clarifications resolved)
├── plan.md              # This file
├── research.md          # Phase 0 — findings + gating verifications V1/V1a/V1b/V4
├── data-model.md        # Phase 1 — entities, validation, materiality
├── quickstart.md        # Phase 1 — end-to-end validation guide
├── other-tax-semantics.md # BLOCKING — PITX formula authority + 4-way code divergence
├── decision-t088a-other-tax-semantics.md # Decision memo — Option 1 vs Option 2
├── contracts/
│   └── cli-contract.md  # Phase 1 — operator command contract
├── checklists/
│   └── requirements.md  # Spec quality checklist
└── tasks.md             # Phase 2 — NOT created by /speckit-plan
```

### Source Code (repository root)

```text
app/
├── Console/Commands/
│   ├── BackfillTransactionTaxes.php          # NEW — dry-run-by-default backfill
│   ├── ArchiveOrphanTaxRows.php               # NEW — archive + chunked delete of NULL-keyed rows
│   ├── SnapshotPreBackfillAggregates.php      # NEW — FR-012 materiality baseline
│   ├── VerifyTaxReconstruction.php           # NEW — oracle check vs post-fix data
│   ├── TaxBackfillMateriality.php            # NEW — FR-009a threshold report
│   └── TaxBackfillShow.php                   # NEW — audit/quarantine lookup (US3)
├── Services/
│   ├── TransactionIngestService.php          # EXISTING — insertTaxes() semantics to reuse
│   ├── DeadlockRetryService.php              # EXISTING — reuse for safe writes
│   └── Backfill/
│       ├── TaxReconstructionService.php      # NEW — payload → tax rows (pure, testable)
│       ├── TaxBackfillRunner.php             # NEW — chunking, idempotency, audit
│       └── OrphanTaxArchiver.php             # NEW — archive/verify/delete orphans
└── Models/
    ├── TransactionTax.php                    # EXISTING — write target
    └── TaxBackfillRecord.php                 # NEW (pending architecture decision)

database/migrations/                          # NEW — audit table (if not reusing SubmissionEvent)

tests/
├── Unit/Services/Backfill/                   # reconstruction logic, malformed payloads
└── Feature/                                  # idempotency, dry-run, scope, no-overwrite
```

**Structure Decision**: Single Laravel project, following existing `app/Console/Commands` + `app/Services` conventions. Reconstruction logic is isolated in a pure service so it can be unit-tested against payload fixtures *and* reused by the verification command — the oracle test (R4) is only possible if the same code powers both paths.

## Key Design Decisions

1. **Recovery source**: `transactions.original_payload` (exact retained values), cross-checked against both the `transactions` tax columns (R3) and the archived orphan rows (V4 — an independent second oracle). `transaction_intake` is not a viable source (research R2).
2. **Correctness proof before mutation**: reconstruction validated against post-fix transactions with known-good tax rows; zero divergence required before any `--apply`.
3. **Idempotency by construction**: insert only where the transaction has zero *linked* tax rows; delete only where `transaction_pk IS NULL`. Both predicates are self-limiting, so re-runs converge and prior manual corrections are honored.
3a. **Archive → insert → reconcile in situ → delete proven subset** (revised 2026-08-10, Architect re-review). Insert-first is strictly safer than delete-first: the originals survive until their replacement is proven *in place*, rollback of a bad insert needs no archive restore, and reconciliation compares what was **actually written** rather than what a dry run *said* it would write. **Coexistence window** (new under insert-first): `transaction_taxes` temporarily holds ~6.5M rows — both the orphan and the linked copy. *(Revised 2026-08-11)* This is now purely temporary: the ~900 orphans from 2026-06-13 are also deleted once their archive write and residual count are verified, so the coexistence window closes uniformly across every day rather than leaving a permanent ~900-row residue in the live table.

Every *known* consumer joins or groups on `transaction_pk`, so NULL-keyed rows drop out: `RefreshDailyTransactionSummaries.php:124`, `SalesReportDataService.php:217`, `BackfillTransactionAggregates.php:139`, `Transaction::taxes()`. **But `docs/specs/vat-reconciliation/90-unscoped-manual-opt-in-only.sql:24` queries `FROM transaction_taxes` with no join** and would double-count during the (now uniformly temporary — revised 2026-08-11) coexistence window. Coexistence duration MUST be bounded (per-day pipelining, FR-014a) and any non-joining consumer MUST be inventoried before the run.
3b. **Deletion is verified per day, then applied uniformly** (revised 2026-08-11). Orphans belonging to successfully reconstructed transactions are deleted once reconciliation passes; the 216 unrecoverable transactions' orphan rows (all 2026-06-13) — **the only surviving record of their tax lines** — are also deleted, once their archive write is independently verified and their residual count confirms exact. The archive, not the live table, is now the durable evidence of record. (Original decision was to retain them live forever; reversed after concluding the Architect's actual concern — preserving evidence — doesn't require permanent live retention, only a verified durable archive.)
3c. **Reconciliation granularity**: compare per-(`tax_type`, `amount`) multisets within a **[0, 10]-second `created_at` tolerance** (revised 2026-08-12 — measured via T068a/research.md V5; not an exact-second match, which the real distribution does not support) rather than per-day — orphans arrive in contiguous id blocks sharing a timestamp, so this is a far sharper test at no extra risk. Unmatched orphans are classified `no_replacement_exists` / `timestamp_out_of_tolerance` / `orphan_content_mismatch` (FR-014); only the last halts. Note this proves *content*, never *attribution*; attribution is carried solely by the post-fix oracle (R4).
4. **Preserve submitted `tax_type` verbatim**: no alias normalization; that is Track B's decision to make, and conflating them would let this feature silently pre-empt an unresolved business decision.
5. **Aggregates regenerate from corrected rows**, never corrected independently (FR-011a).

## Architect Answers (resolved 2026-08-10, re-review #2)

| # | Resolution |
|---|-----------|
| Q1/Q2 | **No replica gate needed.** Both refresh commands aggregate on the primary. Assert connection identity (`@@server_id`, `DATABASE()`) and record it; `MASTER_POS_WAIT` only if the assertion fails. T077 stands, **T038 deleted**. |
| Q3 | **Dedicated table.** `SubmissionEvent.$fillable` has no room for per-transaction before/after tax state, and overloading it pollutes the live ingestion audit stream. Optionally emit one summary `SubmissionEvent` per run. |
| Q4 | **Derived token, not typed.** The archive/reconciliation result hash must be presented to the delete phase and re-computed server-side — unforgeable and unskippable, unlike "obtain authorization". |
| Q5 | **Report only.** 216 rows do not warrant the full quarantine triad; persist with `reason=missing_payload`, surface via Command 4 `--quarantined`. |
| Q7 | **Deferring, not blocking.** Rescope FR-009 to "reports and exports"; record a dashboard follow-up for when the stubbed endpoints are re-enabled — never concurrently with a backfill. |
| Q8 | **Do not extract from `TransactionIngestService`.** It destabilizes the live path for no gain, and per N10 the backfill needs *defect-era* last-wins semantics with a narrower alias set that the live path does not implement. Build an independent pure service; prove equivalence via the R4 oracle. |
| Q9 | **Synchronous console command.** A queue adds redelivery→double-insert as a failure mode for no benefit on a single-operator batch job. |
| Q10 | **Reconstruct taxes for voided transactions** — row-level truth must not depend on a reporting flag. But the FR-014 reconciliation and materiality MUST apply the same void filter as the report path they are compared against (`tsms.reporting.exclude_voids_from_totals`), or before/after diverge for unrelated reasons. |

## Open Items for Architecture Review — ALL RESOLVED

**Superseded by the Architect Answers table above (2026-08-10).** Retained below for provenance only; the table is authoritative. Q6 (tenant isolation) is answered by T080, which requires a partition proof — per-tenant counts, materiality figures, and validation outputs must partition exactly, with no transaction attributed to a tenant other than its own `transactions.tenant_id`.

### Original questions (historical)

| # | Review question |
|---|-----------------|
| Q1 | **How is read-replica catch-up *proven*** before any derived aggregate refresh runs? Not "wait a bit" — what is the verifiable signal? This is the highest-risk item: getting it wrong makes the backfill look successful while silently corrupting the regenerated aggregate layer. |
| Q2 | **Must aggregate refreshes read the primary for this run**, overriding the normal read-replica routing? If so, how is that override scoped to the backfill and prevented from leaking into normal reporting operation? |
| Q3 | **Where does audit/progress state live durably** — dedicated table vs. `SubmissionEvent` reuse — such that it survives interruption and supports both resume and rollback? |
| Q4 | **What is the exact authorization boundary for a live `--apply` run?** Which action requires explicit human sign-off, at what granularity (per run / per tenant / per window), and how is that enforced rather than merely documented? |
| Q5 | **How are quarantine rows persisted and surfaced** so the 216 unreconstructable transactions are reviewable and auditable, never a silent counter? |
| Q6 | **How is tenant isolation proven** across the command, its filters, and every validation output — i.e. what prevents one tenant's data appearing in another's counts, reports, or materiality figures? |
| Q7 | **Does dashboard-route stubbing block FR-009 verification, or merely defer it?** All four dashboard endpoints are stubbed to JSON 404 and T057 requires they stay that way during the run, so FR-009's "reports **and dashboards**" clause is currently unverifiable. Blocking → FR-009 must be rescoped; deferring → a follow-up verification must be recorded against the dashboard workstream. |

### Secondary — raised during planning, still open

| # | Review question |
|---|-----------------|
| Q8 | **Exposing `insertTaxes()` semantics** — it is `protected`; extract a shared writer vs. alternatives, without destabilizing the live ingestion path (research R5). |
| Q9 | **Execution vehicle** — synchronous command vs. dedicated queue (must not touch live intake/processing shards), plus rate-limiting and kill-switch design at ~3.24M inserts. |
| Q10 | **Void/refund handling** — spec edge case; do reconstructed taxes for voided transactions match live semantics? |

**Resolved before the gate**: environment ("production run" = the real run against staging's live data; staging carries real pilot-tenant data; rehearsal on a restored snapshot).

## Complexity Tracking

No constitutional violations to justify (constitution is unpopulated). Complexity is inherent to the risk tier rather than introduced by design choices: the verification command and audit record exist specifically to make an irreversible financial mutation provable and reversible, and are not optional simplification targets.
