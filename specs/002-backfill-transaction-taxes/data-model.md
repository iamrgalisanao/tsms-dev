# Phase 1 Data Model: Backfill Transaction Taxes

**Date**: 2026-08-10

## Existing entities (read/write targets — no schema change proposed to these)

### `transactions` (read-only for this feature)

Source of both the recovery payload and the cross-check values.

| Field | Role in this feature |
|-------|----------------------|
| `id` | PK; the value that belongs in `transaction_taxes.transaction_pk`. Also the chunking cursor. |
| `transaction_id` | Provider-supplied string ID. **Not** a join key for taxes — writing it was the original defect. |
| `tenant_id`, `terminal_id` | Scope/reporting attribution; per-tenant progress and materiality rollups. |
| `original_payload` | **Primary recovery source.** JSON of the submitted payload, including the `taxes` array. |
| `vatable_sales`, `vat_amount`, `sc_vat_exempt_sales` | **Cross-check only** (research R3). Derived from the same payload at ingestion. |
| `created_at` | Defect-window selection boundary. |
| `voided_at` | Void handling (spec edge case). |

### `transaction_taxes` (write target)

| Field | Notes |
|-------|-------|
| `transaction_pk` | FK → `transactions.id`. **Nullable at the DB level** — this is exactly how 3,238,180 orphan rows came to exist (research.md V4). The backfill must never write NULL, must assert non-null before insert, and must restrict deletes to `transaction_pk IS NULL`. |
| `tax_type` | From payload `taxes[].tax_type`. Observed vocabulary includes `VAT`, `VATABLE`/`VATABLE_SALES`, `SC_VAT_EXEMPT_SALES`, `VATEXEMPT`, `EXEMPT`, `OTHER_TAX`. Alias inconsistency is a known, separate open issue (`docs/specs/report-vat-correction-coverage.md` Track B) — this feature MUST preserve submitted values verbatim and MUST NOT attempt alias normalization. |
| `amount` | From payload `taxes[].amount`. |
| `created_at` | **NOT** `now()` — corrected 2026-08-10 (impact-review Blocker 1). MUST be set to the **parent transaction's own `created_at`** value. `insertTaxes()`'s literal `now()` stamp (`TransactionIngestService.php:435-437`) is a live-ingestion-time convention that does not apply to a backfill running years after the original transactions — using it would make every reconstructed row fail FR-014's per-second reconciliation against orphans dated across the defect window. Exact-vs-tolerance behavior is set by research.md V5's measurement, not assumed. |
| `updated_at` | Written only if the column exists (mirrors `insertTaxes()`'s `Schema::hasColumn` guard — this field carries no reconciliation weight, so the live-path convention is safe to reuse here). |

**Validation rules** (mirroring `TransactionIngestService::insertTaxes()`):

- Skip rows missing `tax_type` or `amount`, or with empty `tax_type` / null `amount`; log rather than fail the batch.
- Never insert when the transaction already has ≥1 **linked** (`transaction_pk` non-null) tax row (FR-003, FR-004). NULL-keyed orphans do not count as existing rows — they are handled by the archive/delete path.

## New entity: backfill run/progress record

Needed for FR-007 (auditable record) and R6 (resumability). Exact persistence mechanism is an architecture-gate decision — a dedicated table, or reuse of `SubmissionEvent`.

**Run-level**

| Field | Purpose |
|-------|---------|
| run identifier | Correlates every row-level record to one invocation. |
| window start / end | The defect window actually processed. |
| mode | `dry-run` or `apply`. |
| counters | scanned, reconstructed, skipped-already-present, quarantined, failed. |
| started / completed timestamps | Duration, and detection of interrupted runs. |

**Row-level (per corrected transaction)**

| Field | Purpose |
|-------|---------|
| run identifier | FK to the run. |
| `transaction_pk` | Which transaction was corrected. |
| tenant id | Enables per-tenant materiality rollup (FR-009a) without re-joining. |
| reconstructed tax rows | What was written (type/amount set) — the "after" state. |
| prior state | Confirms "before" was empty; makes the no-overwrite claim auditable. |
| outcome | `applied` \| `skipped_existing` \| `quarantined` \| `failed`, with reason. |

**State transitions**: `pending → applied | skipped_existing | quarantined | failed`. Only `pending → applied` writes tax rows. `quarantined` is terminal pending human review (used when the payload is missing, unparseable, or fails the R3 cross-check).

## Derived data requiring recomputation (FR-006, FR-011a)

Recomputed *after* row-level backfill, from corrected rows — never corrected independently.

| Artifact | Entry point | Affected? |
|----------|-------------|-----------|
| `daily_transaction_summaries` | `reports:refresh-daily-transaction-summaries` | **Yes — `other_tax` only.** Corrected per Architect N7: this command writes `sc_vat_exempt_sales` from the `transactions` column with **no** `transaction_taxes` fallback. The fallback exists only in the *raw rendering* path (`SalesReportDataService`), triggers only when the day-level total is exactly `0.0`, and its list includes the **canonical** `SC_VAT_EXEMPT_SALES`, not merely aliases. |
| `transactions_hourly` | `reporting:refresh` | **No** — derives tax from `transactions` columns (Architect F3) |
| `RefreshHourlyWindowJob` | — | **No** — deprecated no-op (Architect F3) |

**Replica hazard — largely disproven (Architect F4).** `RefreshDailyTransactionSummaries` uses the default (primary) connection throughout; `ReportingRefreshCommand` aggregates on the primary and uses `DB::connection('reporting')` only to resolve a database *name*. The required control is therefore an **assertion of the aggregating connection's identity** (`@@server_id`, `DATABASE()`) recorded in the run record — with a `MASTER_POS_WAIT` gate implemented only if they differ — plus a data-level assertion that the refresh reads post-backfill state.

## Materiality computation (FR-009a)

Per (tenant, reporting month) over the defect window:

- `before` = the **pre-backfill rendered aggregate** captured via the actual report path (FR-012). **NOT zero** — VAT/vatable/SC-VAT-exempt were already correct from `transactions` columns, and `other_tax` had a payload fallback. Treating `before` as zero would flag nearly every tenant for a restatement that did not occur.
- `after` = tax total after backfill
- notify when `|after − before| ≥ PHP 500` **OR** `|after − before| ≥ 1% × after`

Must be reproducible from the persisted row-level records alone (SC-006), so the notification set can be defended after the fact without re-querying mutated state.

## Orphan rows (new — research.md V4)

3,238,180 rows with `transaction_pk IS NULL`, spanning 2026-06-13 → 2026-08-10, ~4 per affected transaction. Schema is `id, transaction_pk, tax_type, amount, created_at, updated_at` — **no linking column**, so they cannot be re-keyed.

| Concern | Requirement |
|---------|-------------|
| Archive | Durable, queryable copy of **all** orphans preserving `id`, `tax_type`, `amount`, timestamps (FR-013), plus reconciled/residual status and a reason code (e.g. `no_replacement_exists`) for rows archived under FR-015b's residual path (FR-013 extended 2026-08-11 — see `tasks.md` T066) |
| Ordering | **Insert-first** — archive, insert, reconcile in situ, then delete (FR-015a) |
| Reconciliation | Inserted rows must reproduce the orphans' per-(`created_at` second, `tax_type`, `amount`) multiset, per day (FR-014), where inserted `created_at` = **the parent transaction's `created_at`** (research.md V5) — never `now()`. Proves **content**, never **attribution** — attribution rests solely on the post-fix oracle (R4) |
| Deletion scope | **Revised 2026-08-11 — all orphans, every day, uniformly.** 2026-06-13's 216 unrecoverable transactions' orphans are archived (their only surviving evidence, per research.md's V1a/V1b consequences) and then **deleted from the live table**, same as every reconciled day. Deletion is gated on the residual count verifying exact (FR-014) and the archive write verifying successful (FR-013) — no day-level exception remains |
| End state | `transaction_taxes` reaches **zero permanent NULL-keyed orphans**, a precondition for eventually enforcing `transaction_pk NOT NULL` (schema-hardening follow-on, out of this feature's scope but newly unblocked by it). **Caveat (2026-08-11 drift revalidation)**: this clears the *known* blocker only — `fk_tx_taxes_pk`'s `ON DELETE` action is still unverified (T097); if it is `SET NULL`, future `transactions` deletions could reintroduce NULL-keyed rows through a path this feature doesn't touch. "Unblocked" means the hardening ticket can start, not that it's guaranteed safe to ship without checking T097 first |
| Rollback | A bad insert rolls back without touching the archive, since originals are still present. Restoring a bad delete requires re-inserting from the archive (both the reconciled-day rows and, if ever needed, the 216's archived rows) |

## Accessor hazard (FR-018 — BLOCKING)

`Transaction::$appends` exposes `net_amount` and `calculated_net_sales`, both computed via `otherTaxSum()`, which sums **all non-`VAT`** linked rows — including `VATABLE_SALES`. Against the PITX formula that is incoherent: vatable sales is the base of GROSS, not a deduction.

Currently inert (no linked rows in the window → falls through to the `sc_vat_exempt_sales` column). **Reconstruction activates it**, changing an API-visible value on 809,107 transactions. *(Corrected: both `TransactionValidationService::validateAmounts()` and `::validateAmountReconciliation()` are unreachable dead code — `validateTransaction()`, their only path into production, is a passive no-op that calls neither. There is no validator exposure at all. Note this is a different, unrelated `validateAmounts()` from the live method of that name in `JobProcessingService`.)* Must be fixed or isolated before backfill — see [other-tax-semantics.md](other-tax-semantics.md).

## Explicitly out of scope

- No schema change to `transactions` or `transaction_taxes`.
- No alias normalization of `tax_type` (belongs to the separate Track B work).
- No change to the live ingestion write path — it is already fixed and verified.
- No re-enabling of the four stubbed dashboard endpoints.
