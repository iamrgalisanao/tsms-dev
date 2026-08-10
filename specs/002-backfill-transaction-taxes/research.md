# Phase 0 Research: Backfill Transaction Taxes

**Date**: 2026-08-10
**Status**: Findings confirmed; empirical gating verifications V1/V1a/V1b/V4 cleared against the live staging database on 2026-08-10. **Two architect re-reviews have since corrected material claims in this document** — see the CORRECTED note in R1, the retraction in R8, and V4. Feasibility risk is closed; residual risk is execution-side and governance-side (finance sign-off withdrawn, see spec.md).

## R1: What was actually lost, and what survived

**Decision**: Treat `transaction_taxes` as the *only* lost artifact. Do not assume broader data loss.

**Rationale**: At the stale staging commit `622d7f4`, the routed `TransactionController::storeOfficial()` wrote child records inline. Within the same block:

- `TransactionAdjustment::create(['transaction_pk' => $transaction->id, ...])` — **correct column**, rows persisted.
- `TransactionTax::create(['transaction_id' => $transaction->transaction_id, ...])` — `transaction_id` is not in `TransactionTax::$fillable` (which expects `transaction_pk`), so Eloquent silently discarded the attribute.

**CORRECTED 2026-08-10 (V4).** An earlier draft of this section said the insert "failed/orphaned" and did not resolve which. That ambiguity was load-bearing and wrong. **The inserts SUCCEEDED**, writing rows with `transaction_pk = NULL`:

- `TransactionTax::$fillable` omits both `transaction_id` and any key, and no `preventSilentlyDiscardingAttributes()` exists in `app/Providers/` — Laravel silently discards rather than throwing.
- `config/database.php:55` sets `'strict' => false` on the `mysql` connection, so MySQL supplies implicit defaults instead of erroring on the omitted column. `transaction_taxes.transaction_pk` is nullable.
- **Decisive**: `622d7f4` line 1442 wraps the parent insert, adjustments, and taxes in a single `DB::transaction(...)`. Had the tax insert thrown, the parent row and adjustments would have rolled back too — but both demonstrably persisted. Therefore the tax insert committed.

So the defect is **lost linkage, not lost data**. `tax_type` and `amount` were written correctly; only the foreign key was dropped. Confirmed empirically by V4 below.

The adjustments/taxes asymmetry still holds: adjustments used the correct `transaction_pk` and are properly linked. Confirmed by reading the blob at `622d7f4` directly (lines ~1442-1465), not inferred.

**Alternatives considered**: Assuming payload-wide loss — rejected; contradicted by the code and by the surviving adjustment rows.

## R2: Recovery source — `transactions.original_payload` (primary)

**Decision**: Reconstruct tax rows from `transactions.original_payload`, which holds the exact provider-submitted JSON including the `taxes` array.

**Rationale**: The same stale code path that dropped the tax rows *did* persist the full payload:

```php
if (Schema::hasColumn('transactions', 'original_payload')) {
    $txPayload['original_payload'] = json_encode($transactionData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
```

`$transactionData` is the whole per-transaction payload, and `TSMSTransactionRequest` validates `transaction.taxes` as `required|array|min:4` with `tax_type` and `amount` per row. So the original tax lines should be recoverable **exactly** — this satisfies FR-011's per-line-item fidelity without re-deriving or estimating anything.

Column added by `2025_09_27_000012_add_original_payload_to_transactions.php` (text) / `2026_06_13_000002_add_original_payload_to_transactions_table.php` (json), both predating the defect window.

**Alternatives considered**:

- *`transaction_intake.payload`* — **rejected outright** (V1a confirmed the primary source covers all but 216 rows; this fallback is not needed). At `622d7f4`, `TransactionIntakeService` existed but nothing referenced it (`git grep -l` at that commit matched only the file defining it); the route pointed at the inline `storeOfficial()`, which never created an intake row. Defect-window transactions therefore likely have **no** intake row. Confirmed moot by V1a — not part of the implementation path.
- *Re-deriving from transaction columns* — see R3; kept as a cross-check, not the source.

## R3: Secondary cross-check — transaction-level tax columns

**Decision**: Use `transactions.vatable_sales`, `vat_amount`, and `sc_vat_exempt_sales` as an independent validation signal, not as the reconstruction source.

**Rationale**: The stale code derived these three columns *from the same `taxes` array* before persisting the transaction:

| Payload `tax_type`         | Transaction column      |
|----------------------------|-------------------------|
| `VATABLE_SALES`            | `vatable_sales`         |
| `VAT` or `VAT_AMOUNT`      | `vat_amount`            |
| `SC_VAT_EXEMPT_SALES`      | `sc_vat_exempt_sales`   |

Because they share an origin, they are a strong consistency check: a reconstructed tax row set whose VAT figure disagrees with the stored `vat_amount` indicates a corrupt or truncated payload and must be quarantined rather than written. They are *not* sufficient as a source — they cover only 3 tax types, and the payload carries at least 4 rows.

## R4: Reconstruction correctness must be proven against known-good data

**Decision**: Validate the reconstruction routine against **post-fix** transactions (2026-08-10 ~10:00 onward) where both `original_payload` and correct `transaction_taxes` rows exist.

**Rationale**: This gives a true oracle. Running the reconstruction against a post-fix transaction must reproduce the tax rows that the fixed `TransactionIngestService::insertTaxes()` actually wrote — byte-for-byte on `tax_type` and `amount`. Any divergence is a bug in the backfill, discoverable *before* touching historical data. This is the single highest-value test in the feature and satisfies FR-008.

## R5: Write path — reuse `insertTaxes()` semantics, do not reimplement

**REVISED 2026-08-10 (Architect Q8/N10): do NOT extract or reuse `insertTaxes()`.** Extraction destabilizes the live ingestion path for no gain, and — decisively — the backfill needs **defect-era last-wins semantics with a narrower alias set** that the live path does not implement. Build `TaxReconstructionService` as an independent pure service and prove equivalence through the R4 oracle: equivalence proven by test beats equivalence asserted by shared code. Mirror `insertTaxes()`'s malformed-row *guard* behaviour, but do not call it.

**Rationale**: Divergence between the backfill's write logic and the live write logic would reintroduce exactly the class of inconsistency this feature exists to eliminate. Note `insertTaxes()` is `protected`; exposing it safely (extracted collaborator or a dedicated writer service) is an implementation decision for the architecture gate, not something to solve by copy-paste.

## R6: Idempotency and resumability

**Decision**: Idempotency keyed on "transaction has zero existing tax rows", plus a persisted per-transaction progress/audit record; chunked cursor iteration ordered by `transactions.id`.

**Rationale**: FR-003/FR-004 require never touching already-correct data and never double-writing. Checking for existing rows before insert makes re-runs safe by construction and automatically honors the "prior manual correction" edge case. `DeadlockRetryService` already exists for safe concurrent writes and should be reused. The 2026-08-07 index migration added `idx_tx_ingest_tenant_terminal_status_created` and related indexes, which the selection query should exploit.

## R7: Operator interface — follow `LicenseBindingBackfillCommand`

**Decision**: Model the console command on `app/Console/Commands/LicenseBindingBackfillCommand.php`.

**Rationale**: It is this repo's established precedent for exactly this shape of job: **dry-run by default**, `--apply` to persist, `--chunk`, `--limit`, `--json`, up-front schema validation returning `FAILURE` before any mutation. Adopting it means operators already know the ergonomics, and dry-run-by-default is the correct safety posture for a financial backfill. `IngestionQuarantine{List,Show,Replay}` is the precedent for the list/inspect/act triad if the feature needs a quarantine surface for unreconstructable rows.

## R8: Downstream aggregate recomputation

**Decision**: Recompute derived reporting data *after* row-level backfill completes, via existing commands rather than new aggregation logic.

**Rationale**: FR-006/FR-011a require summaries to be regenerated *from* corrected rows. Existing entry points:

- `reports:refresh-daily-transaction-summaries` (`RefreshDailyTransactionSummaries`) → `daily_transaction_summaries`
- `reporting:refresh {table} {--hours=} {--table=}` (`ReportingRefreshCommand`) → hourly/rollup tables
- `app/Jobs/Reporting/{RefreshHourlyWindowJob,InvalidateCountCacheJob}`

**RETRACTED 2026-08-10 (Architect F3/F4).** Two corrections to the list above:

- `RefreshHourlyWindowJob` is a **deprecated no-op** and `transactions_hourly` derives tax from `transactions` columns — neither is affected. Only `reports:refresh-daily-transaction-summaries` genuinely changes.
- The replica-lag hazard **largely does not exist**. `RefreshDailyTransactionSummaries` uses the default (primary) connection throughout, and `ReportingRefreshCommand` aggregates on the primary, using `DB::connection('reporting')` only to resolve a database *name*. The correct control is an assertion of the aggregating connection's identity (T077), not a lag wait. T038 is deleted.

Scope of recomputation is bounded by the defect window, and the refresh itself must be scoped per tenant/day (Architect N8) — it otherwise builds an unbounded derived table over all of `transaction_taxes` and wraps ~10⁴ inserts in one transaction.

## R9: Live-ingestion safety

**Decision**: Backfill runs off the live ingestion queues, at bounded rate, and must not hold long transactions on `transactions` or `transaction_taxes`.

**Rationale**: FR-005, and hard-won evidence from the 2026-08-10 incident: a long-running `ALTER`/transaction on `transactions` queued behind idle connections and then blocked every subsequent query on that table, taking the site down. Small chunks with short transactions, no table-wide locks, and a kill switch are mandatory. Do **not** dispatch onto `transaction-intake:s{N}`/`transaction-processing:s{N}` shards, which would contend with live traffic; the `IngestionQueueRouter` sharding is for live tenant traffic, not batch repair.

**Operational note**: four dashboard endpoints (`dashboard/{metrics,charts,notifications,terminal-performance}`) are currently stubbed to JSON 404 on staging pending DB stability. Re-enabling them is *not* part of this feature, but running a heavy backfill while they are re-enabled would recreate the original pile-up conditions — sequencing matters.

## Gating Verifications — CLEARED 2026-08-10

*All resolved; results recorded inline below. V2 is moot and V3 was superseded by V1a. Queries retained for reproducibility only — do not re-run as gates.*

These are empirical and cannot be answered from source. Implementation must not begin until all three are confirmed; a failure on **V1** invalidates the entire per-line-item approach and forces re-opening the FR-011 fidelity decision.

### V1 RESULT (run 2026-08-10) — PARTIAL PASS

Over `2026-06-01 .. 2026-08-10` (a provisional window; V3 not yet run):

| Metric | Count | Share |
|--------|-------|-------|
| Total transactions | 963,506 | 100% |
| `original_payload IS NULL` | 156,647 | 16.3% |
| Valid payload containing `$.taxes` | 806,859 | 83.7% |

The two categories sum **exactly** to the total — there is no malformed-but-present tail. Every non-null payload carries a taxes array, so the primary recovery source is sound for the rows that have it.

**Consequences:**

1. Volume is materially larger than the plan's original estimate — ~963K transactions in this provisional window, not "several hundred thousand". Chunking/runtime design must assume ~1M rows × ≥4 tax rows.
2. The 16.3% NULL-payload population needs a disposition before implementation. **Leading hypothesis (unconfirmed):** these are synthetic rows from `app/Jobs/BulkGenerateTransactionsJob.php:41`, which calls `Transaction::create(...)` without `original_payload` and without taxes — i.e. load-test data that never had taxes and requires no backfill. Every real ingestion path (stale controller sites at 622d7f4 lines 412/1432/2126, and current `TransactionIngestService.php:313`) does write `original_payload`.
3. This count is **not yet** the backfill population: it counts all transactions in the provisional window, not only those *missing* tax rows. V3 must run before any figure is quoted as the scope.

**Open follow-ups (V1a, V1b below).** If V1b disproves the synthetic-data hypothesis and the NULL rows turn out to be real tenant transactions, the per-line-item approach (FR-011) is unachievable for them and the fidelity decision must be re-opened with the user.

### V1a / V1b RESULT (run 2026-08-10) — FULL PASS

**The synthetic-data hypothesis above was WRONG and is retracted.** V1b showed the NULL-payload rows spread across 20+ real tenants with 1-6 terminals each — ordinary tenant traffic, not `BulkGenerateTransactionsJob` output. The real explanation is better:

**The NULL-payload rows all stop on 2026-06-12/13, and they all already have tax rows.** Payload retention *began* on the same 2026-06-13 deployment that broke tax capture (`8b77c65b "Preserve raw official transaction payloads"`, batched with `4239be65 "Remove webapp forwarding"`). So the two populations are complementary, not overlapping:

| Period | `original_payload` | `transaction_taxes` | Backfill needed? |
|--------|--------------------|---------------------|------------------|
| Before 2026-06-13 | NULL (not yet written) | **Present** | No |
| 2026-06-13 → 2026-08-10 ~10:00 | **Present** | Missing | **Yes — recoverable** |
| After 2026-08-10 ~10:00 | Present | Present | No |

The deployment that caused the defect also started retaining exactly the data needed to repair it.

**Confirmed scope:**

| Metric | Value |
|--------|-------|
| **Defect window** | **2026-06-13 → 2026-08-10 ~10:00** (59 days) |
| Transactions in window | 811,801 |
| **Missing tax rows AND recoverable** | **808,891** (99.97%) |
| Missing tax rows, NOT recoverable | **216** (0.03%, all on 2026-06-13) |
| Pre-window anomalies (unrelated) | 6 (2026-05-14, 05-23, 06-06, 06-10) |
| Estimated tax rows to insert | ~3.24M (≥4 per transaction) |

**Cross-validation**: summing `missing_recoverable` across the window and excluding the 2,032 pre-fix rows on 2026-08-10 gives exactly 806,859 — matching V1's independently-computed `has_taxes_array`. Two different queries agree to the row.

**Boundary is unambiguous**: 2026-06-12 shows 10,825/10,825 with taxes; 2026-06-13 shows 0/225. 2026-08-10 is the expected partial day (2,694 with taxes, 2,032 without) straddling the ~10:00 fix.

**Consequences for the plan:**

1. **FR-011 per-line-item fidelity is achievable for 99.97% of the affected population.** No need to re-open the fidelity decision.
2. The 216 unrecoverable rows (all on the 2026-06-13 transition day, which saw only 225 transactions total — a deployment-day traffic collapse) go to `quarantined`, not guessed at. At 0.03% they are a reporting footnote, but they must be surfaced, not silently dropped.
3. Scale is confirmed at ~809K transactions / ~3.24M inserted rows — chunking, runtime, and replica-lag design must assume this magnitude.
4. **V2 is now moot** — `transaction_intake` is not needed as a fallback, since the primary source covers all but 216 rows.

**V1 — Is `original_payload` populated and does it contain taxes, for the defect window?**

```sql
SELECT COUNT(*) AS total,
       SUM(original_payload IS NULL) AS null_payload,
       SUM(original_payload IS NOT NULL
           AND JSON_VALID(original_payload)
           AND JSON_EXTRACT(original_payload, '$.taxes') IS NOT NULL) AS has_taxes_array
FROM transactions
WHERE created_at >= '2026-06-01' AND created_at < '2026-08-10';
```

**V2 — Do defect-window transactions have intake rows (i.e. is the fallback source real)?**

```sql
SELECT COUNT(*) AS tx_total,
       SUM(EXISTS (SELECT 1 FROM transaction_intake ti
                   WHERE ti.tenant_id = t.tenant_id
                     AND JSON_UNQUOTE(JSON_EXTRACT(ti.payload, '$.transaction_id')) = t.transaction_id)) AS with_intake
FROM transactions t
WHERE t.created_at >= '2026-08-05' AND t.created_at < '2026-08-10';
```

**V3 — Exact defect-window start date** (spec Assumptions leaves this open; establish from the first date with zero tax capture):

```sql
SELECT DATE(t.created_at) AS day,
       COUNT(*) AS total,
       SUM(EXISTS (SELECT 1 FROM transaction_taxes tt WHERE tt.transaction_pk = t.id)) AS with_tax
FROM transactions t
WHERE t.created_at >= '2026-05-01'
GROUP BY DATE(t.created_at)
ORDER BY day;
```

**V1a — Combined: defect-window boundary AND recoverability of the rows that actually need backfill.** Supersedes V3 (same scan). Read-only; expect 1-3 minutes over ~1M rows.

```sql
SELECT DATE(x.created_at) AS day,
       COUNT(*) AS total,
       SUM(x.has_tax) AS with_tax,
       SUM(x.has_tax = 0 AND x.payload_ok = 1) AS missing_recoverable,
       SUM(x.has_tax = 0 AND x.payload_ok = 0) AS missing_unrecoverable
FROM (
    SELECT t.id, t.created_at,
           EXISTS (SELECT 1 FROM transaction_taxes tt WHERE tt.transaction_pk = t.id) AS has_tax,
           (t.original_payload IS NOT NULL
            AND JSON_VALID(t.original_payload)
            AND JSON_EXTRACT(t.original_payload, '$.taxes') IS NOT NULL) AS payload_ok
    FROM transactions t
    WHERE t.created_at >= '2026-05-01' AND t.created_at < '2026-08-11'
) x
GROUP BY DATE(x.created_at)
ORDER BY day;
```

Establishes the true defect-window start (first day where `with_tax` collapses to ~0) and, critically, `missing_unrecoverable` — the only population that genuinely blocks FR-011.

**V1b — Characterize the NULL-payload rows (synthetic vs. real tenant traffic).**

```sql
SELECT t.tenant_id,
       COUNT(*) AS null_payload_rows,
       COUNT(DISTINCT t.terminal_id) AS terminals,
       MIN(t.created_at) AS first_seen,
       MAX(t.created_at) AS last_seen
FROM transactions t
WHERE t.created_at >= '2026-06-01' AND t.created_at < '2026-08-10'
  AND t.original_payload IS NULL
GROUP BY t.tenant_id
ORDER BY null_payload_rows DESC
LIMIT 20;
```

Synthetic load-test data should cluster hard (few tenants/terminals, burst timestamps). Even spread across many tenants and terminals would disprove the hypothesis and escalate this to a user decision.

## V4: Orphan census — CONFIRMED 2026-08-10 (post-Architect-gate)

**Every dropped tax line is still in the table, NULL-keyed.** Missed by V1/V1a/V1b because all three probed `EXISTS (SELECT 1 FROM transaction_taxes tt WHERE tt.transaction_pk = t.id)` — a predicate structurally blind to NULL-keyed rows. They reported "0 tax rows captured" for data that was physically present throughout.

| Metric | Value |
|--------|-------|
| Orphan rows (`transaction_pk IS NULL`) | **3,238,180** |
| Transactions missing linked taxes | 809,107 |
| Ratio | **4.002** |
| Date span | 2026-06-13 → 2026-08-10 (exactly the defect window) |

Most days are an *exact* 4× match (06-14: 2,927×4=11,708 · 06-25: 33,168×4=132,672 · 08-10: 2,032×4=8,128). Days marginally above 4.00 are consistent with `TSMSTransactionRequest` validating `taxes` as `min:4` — some payloads carried more.

### Orphans are NOT re-keyable

`transaction_taxes` schema is `id, transaction_pk, tax_type, amount, created_at, updated_at` — **no linking column exists**. `SELECT ... transaction_id` returns MySQL error 1054.

**This corrects Architect finding F6**, which inferred from `docs/specs/vat-reconciliation/00-shared-fragments.sql` that `transaction_taxes.transaction_id` survived migration `2025_08_13_000014`. It did not — for this table the drop succeeded. That SQL package is simply broken (independently confirmed live earlier: `Unknown column 'tt.transaction_id'`). The migration's defective single-quoted FK-drop loop (line 34) is real but did not save the column here.

Re-keying by correlating contiguous id blocks and `created_at` is technically conceivable — orphans appear in 4-row blocks sharing a timestamp — but is **rejected**: it is a heuristic applied to financial data that would yield the same values `original_payload` already provides authoritatively.

### Observed tax vocabulary

`VAT`, `VATABLE_SALES`, `SC_VAT_EXEMPT_SALES`, `OTHER_TAX` — exactly matching the R3 column mapping. Many amounts are `0.00`.

### Consequences

1. **The insert-only invariant is void.** The feature is now **archive → insert → reconcile in situ → delete the proven subset** (insert-first, revised per Architect re-review #2). 2026-06-13's unrecoverable orphans are archived but **never deleted** — no replacement exists for them. This requires its own authorization and rollback story (see plan.md).
2. **Orphans are a free independent cross-check.** Reconstruction from `original_payload` must reproduce the same per-day count and `tax_type`/`amount` multiset before anything is deleted — a strong corroboration the plan previously lacked.
3. **Partially reinforces F2 — but the payload-fallback claim was WRONG (corrected per Architect N1).** `VAT`/`VATABLE_SALES`/`SC_VAT_EXEMPT_SALES` were still written to `transactions` columns and feed daily summaries directly, so those figures were correct. **`OTHER_TAX` was not covered**: `FinanceCalculationService::NON_OTHER_TAX_TYPES` (lines 49-65) includes `'OTHER_TAX'`/`'OTHER-TAX'`, so the payload fallback contributes `0.00` for exactly this vocabulary. During the window the merge was effectively `max(SUM(tax_exempt), 0, 0)` — and `tax_exempt` is a **boolean** summed as currency. The true `other_tax` impact is therefore larger than earlier drafts stated, and the pre-backfill baseline is not a trustworthy peso figure.

**Decisions taken (user, 2026-08-10)**: archive orphans to a durable table, verify, then chunked-delete. Proceed with the feature but **re-baseline the business case** — rewrite the justification and materiality model honestly and let finance re-confirm priority against the smaller real impact.

## Resolved Unknowns Summary

| Unknown | Resolution |
|---------|-----------|
| Is the tax data recoverable at all? | **Yes — confirmed (V1a)**. 808,891 of 809,107 affected transactions (99.97%) hold their submitted `taxes` array in `original_payload`. The 216 exceptions are quarantined, not reconstructed. |
| Was the payload derived or submitted? | Submitted by POS; `insertTaxes()` consumes `$payload['taxes']`, it does not compute taxes |
| Can we prove reconstruction is correct? | Yes — post-fix transactions provide a ground-truth oracle (R4) |
| Is `transaction_intake` usable? | Not needed — V1a showed the primary source covers all but 216 rows; V2 moot |
| Defect window end | 2026-08-10 ~10:00 (partial day: 2,694 captured / 2,032 missing) |
| Defect window start | **2026-06-13** — confirmed by V1a; clean boundary (06-12: 100% captured, 06-13: 0%) |
| Affected population | 808,891 recoverable + 216 unrecoverable |
