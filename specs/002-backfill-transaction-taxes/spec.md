# Feature Specification: Backfill Transaction Taxes

**Feature Branch**: `002-backfill-transaction-taxes`

**Created**: 2026-08-10

**Status**: Draft

**Input**: User description: "Historical backfill of missing transaction tax records for POS transactions ingested during a defect window on the TSMS staging deployment. A code defect (since fixed and confirmed resolved as of 2026-08-10 ~10:00) caused every ingested POS transaction's tax line items to be silently dropped system-wide, across all tenants active during the defect window, for roughly the two months prior to the fix. Recover accurate tax reporting for the affected historical transactions so that tenant financial reports, dashboards, and compliance exports for the defect window are correct, complete, and trustworthy — matching what would have been recorded had the defect never occurred."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Finance user sees corrected tax figures in reports (Priority: P1)

A finance/commercial user viewing a tenant's sales or finance report for a date within the defect window sees complete, accurate tax breakdowns (VAT, VAT-exempt, and other tax types) instead of the zero/missing figures currently shown.

**Why this priority**: This is the core value of the feature — restoring trust in financial reporting for the ~87 affected tenants. Nothing else in this feature matters if reports still show incomplete tax data after recovery.

**Independent Test**: Pick any tenant and any date inside the confirmed defect window, load that tenant's finance report for that date, and confirm tax totals are non-zero and reconcilable against the transaction's own line items — independent of whether compliance exports or audit trails have been built yet.

**Acceptance Scenarios**:

1. **Given** a transaction was ingested during the defect window and its original submission included tax lines, **When** recovery has completed, **Then** the transaction has associated tax records matching the tax lines in that retained original submission, exactly as the live ingestion path would have recorded them.
2. **Given** a tenant's report component that genuinely depended on `transaction_taxes` for a date in the defect window (principally `other_tax`; and `sc_vat_exempt_sales` only for alias variants outside the ingestion path's accepted list), **When** recovery has completed and aggregates are refreshed, **Then** that component reflects the corrected value. **Note**: VAT, vatable sales, and SC-VAT-exempt totals were sourced from `transactions` columns and were already correct — see the Business Case Re-baseline below.
3. **Given** a transaction outside the defect window already has correct tax records, **When** recovery runs, **Then** that transaction's tax records are left unchanged.

---

### User Story 2 - Compliance export reflects corrected data (Priority: P2)

A compliance/back-office user generating or reviewing a BIR/CSMR-style compliance export for a period that overlaps the defect window sees accurate tax figures for every affected tenant, not the incomplete figures produced while the defect was active.

**Why this priority**: Compliance exports carry regulatory weight; incomplete tax figures in this context are a higher-severity problem than an internal dashboard being wrong, but this depends on Story 1's corrected underlying data existing first.

**Independent Test**: Regenerate a compliance export for a period inside the defect window after recovery and confirm every affected tenant's tax lines are populated and internally consistent with their transaction data.

**Acceptance Scenarios**:

1. **Given** recovery has completed for the defect window, **When** a compliance export is generated for a period overlapping that window, **Then** every affected tenant's tax figures in the export are complete and non-zero for transactions that had taxable line items.

---

### User Story 3 - Internal user can audit what was corrected (Priority: P3)

An internal admin/ops user can review which transactions were corrected, when, by what process run, and the before/after tax values, in order to answer a tenant dispute or internal audit question about the correction.

**Why this priority**: Valuable for trust and dispute resolution, but the recovery itself (Stories 1-2) delivers the primary business value even before a dedicated audit view exists.

**Independent Test**: After recovery, pick any corrected transaction and confirm there is a retrievable record showing it was touched by the recovery process, when, and what tax records were added.

**Acceptance Scenarios**:

1. **Given** a transaction was corrected by the recovery process, **When** an internal user looks up that transaction's history, **Then** they can see that a correction occurred, when it ran, and what tax records resulted.

---

### User Story 4 - Materially-affected tenants are notified (Priority: P3)

A tenant whose corrected tax totals changed materially for a reporting month is proactively informed that their historical figures were restated, so they can reconcile their own records. Tenants with immaterial corrections are not notified, keeping this from becoming a noisy comms event.

**Why this priority**: Protects trust with the tenants most likely to have already acted on the wrong numbers, but the data correction itself (Stories 1-2) must land first — notifying before the data is right would be worse than not notifying at all.

**Independent Test**: After recovery, produce the list of tenants crossing the materiality threshold and confirm it correctly includes tenants with large corrections and excludes those with trivial ones, verifiable against the per-tenant before/after totals.

**Acceptance Scenarios**:

1. **Given** a tenant whose corrected tax total for an affected reporting month changed by at least PHP 500 or at least 1%, **When** the notification step runs, **Then** that tenant is included in the notification set.
2. **Given** a tenant whose corrections fall below both thresholds for every affected month, **When** the notification step runs, **Then** that tenant is not notified but still receives corrected reports.

---

### Edge Cases

- What happens to a transaction that was voided or refunded during the defect window — does it still receive corrected tax records, consistent with how void/refund transactions are taxed today?
- What happens if a transaction's source data needed to reconstruct its tax line items is itself incomplete or corrupted (not just missing the tax rows)?
- What happens to a tenant that only became active partway through the defect window, or stopped transacting before it ended — is scope correctly limited to their actual active dates?
- What happens if the recovery process is interrupted partway through (e.g., restarted) — must it resume without creating duplicate or inconsistent tax records?
- What happens if a transaction in the defect window was already manually corrected by support staff before this recovery runs — recovery must not overwrite or conflict with that manual correction.
- What happens to cached/pre-aggregated reporting figures (daily/weekly/monthly summaries) that were computed using the incomplete tax data during the defect window — do they need to be recomputed, not just the underlying transaction-level records?

## Business Case Re-baseline (2026-08-10)

The original framing of this feature — "reports show zero/incomplete tax figures" — **was not accurate**, and is corrected here rather than quietly preserved.

**What actually happened**: the defective code wrote `transaction_taxes` rows successfully but with `transaction_pk = NULL`. Tax *values* were never lost; the *linkage* was. 3,238,180 orphan rows sit in the table today (research.md V4).

**What tenants actually saw**: VAT, vatable sales, and SC-VAT-exempt figures were correct — `RefreshDailyTransactionSummaries` sources them from `transactions` columns, which the defective path still populated.

**`other_tax` was NOT covered — corrected 2026-08-10 (Architect N1).** An earlier draft of this section claimed the payload fallback "covered the gap". **It does not.** `FinanceCalculationService::NON_OTHER_TAX_TYPES` (lines 49-65) *includes* `'OTHER_TAX'` and `'OTHER-TAX'`, and `adjustmentComponentsFromPayload()` contributes to `other_tax` only for types **not** in that list. So the payload term returns `0.00` for rows literally typed `OTHER_TAX` — precisely the vocabulary the orphan census observed. During the defect window the merge was effectively `max(SUM(tax_exempt), 0, 0)`.

Consequently the true `other_tax` report impact is **larger** than the earlier statement implied, not smaller.

Two further defects surfaced while establishing this, both pre-existing and neither introduced by this feature:

- Three code paths classify `OTHER_TAX` three different ways: the SQL exclusion lists in `RefreshDailyTransactionSummaries.php:120` and `SalesReportDataService.php:223` **omit** it (so linked rows count), while `NON_OTHER_TAX_TYPES` **includes** it (so payload rows do not). Logged as a separate defect.
- `transactions.tax_exempt` is a **boolean** (`Transaction.php:317`) but is summed as currency — `COALESCE(SUM(t.tax_exempt),0)` (`RefreshDailyTransactionSummaries.php:68`) — and fed into `other_tax` as a peso amount. The pre-backfill `other_tax` baseline is therefore partly a *count* of tax-exempt transactions. See FR-016.

**Why the work is still justified**:

1. `transaction_taxes` is the authoritative row-level tax table and is currently unusable for the window — 809,107 transactions have no linked tax rows.
2. The tax-alias workstream (`docs/specs/report-vat-correction-coverage.md` Track B) joins this table directly; 809K transactions are invisible to it.
3. 3.24M NULL-keyed rows pollute any query that does not join (e.g. the tax-type inventory in `docs/specs/vat-reconciliation/`), and would double-count against newly inserted rows.
4. The `max()` payload fallback is a fragile accident, not a designed contract; reports should not depend on it.
5. Compliance and dispute resolution need per-transaction tax detail.

**What this changes**: the driver is **source-of-truth integrity**, not "fixing wrong reports".

### Finance sign-off — WITHDRAWN, RE-CONFIRMATION REQUIRED

A sign-off was obtained on 2026-08-10 stating that expected deltas were "limited mainly to `other_tax` and alias-sensitive `sc_vat_exempt_sales`", on the basis that the payload fallback covered `other_tax`.

**That basis was factually wrong (N1 above), and the sign-off is therefore treated as INVALID.** Finance approved against an understated impact statement. Per user direction (2026-08-10): *"Do not treat the prior sign-off as valid. They approved against a false impact statement, and the corrected OTHER_TAX behavior could change priority or required comms."*

**Blocking**: fresh finance sign-off required against the corrected statement above, explicitly covering:

1. `other_tax` for defect-window dates was **not** backfilled by any fallback — the impact is larger than previously stated.
2. The `tax_exempt` boolean-as-currency defect (FR-016) means the pre-backfill `other_tax` baseline is not a trustworthy peso figure.
3. **`other_tax` is defined four different ways across the codebase**, and only one matches the PITX business formula. Per [other-tax-semantics.md](other-tax-semantics.md): the payload-derived path excludes `OTHER_TAX` entirely (so it contributes ~`0.00`), while the SQL path counts it. The correction's visible effect will therefore be **non-uniform across tenant-days** — and because the aggregate is a `max()` including a boolean-count term, on high-volume days `other_tax` may not change at all while on low-volume days it will. Nobody has quantified this; the FR-012 snapshot will.
4. **A separate blocking defect (FR-018)**: reconstruction would change `net_amount` / `calculated_net_sales` on every API response for 809,107 transactions — a ≈89% collapse on the worked example — because `otherTaxSum()` treats `VATABLE_SALES` as a deduction. *(Corrected 2026-08-10: an earlier draft also claimed mass validation failures via `validateAmounts()`. That was **wrong** — `validateAmounts()` assigns `$otherTaxSum` at line 593 and never uses it. The real validator exposure is `validateAmountReconciliation()`, whose tax loop is empty today and becomes non-empty after reconstruction — a smaller effect, and in the correct direction.)* This must be resolved before any live run, independently of finance's priority call.
5. **Principle confirmation only (S7, reduced)**: *"Should TSMS continue deducting VAT-exempt sales from the API-visible `net_amount`/`calculated_net_sales` fields, per the PITX formula?"* The mechanism question is closed — design decision D7 keeps the deduction as an explicit accessor term, so there is **no** PHP 13.8M movement to approve. Background: `Transaction::otherTaxSum()` adds the `sc_vat_exempt_sales` **column** when no `SC_VAT_EXEMPT_SALES` **row** exists. Every window transaction currently has zero rows, so the fallback is live today; reconstruction inserts such a row and **disables** it, shifting `net_amount` by the column amount even with the allow-list fix applied. PITX NET SALES does deduct VAT Exempt Sales, so removing the fallback moves further from the formula — but `otherTaxSum()` is meant to denote `OTHER_TAX`, not a mixed net-sales adjustment helper. Finance must decide keep-vs-remove, presented with the quantified exposure: count of window transactions with non-zero `sc_vat_exempt_sales`, total peso value, and expected aggregate `net_amount` delta.
6. Whether the corrected impact changes priority, and whether it changes required tenant communications.

What finance previously affirmed and which still stands independently: execution risk remains **high**, and the reduced business impact does **not** reduce the operational risk tier.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST identify every transaction within the defect window that has zero associated tax records but should have had taxable line items.
- **FR-002**: System MUST reconstruct each identified transaction's tax line items by replaying the tax values retained in that transaction's stored original submission, applying the same validation and skip rules the live ingestion path applies today. The system MUST NOT compute or estimate tax amounts from line items — the original submitted values were retained and are the authoritative source (see research.md R1/R2).
- **FR-003**: System MUST NOT alter or duplicate tax records for transactions that already have correct tax data, whether from outside the defect window or from a prior manual correction.
- **FR-004**: System MUST be safely re-runnable: running the recovery process more than once against the same transaction MUST NOT create duplicate or double-counted tax records.
- **FR-005**: System MUST NOT disrupt live ingestion or processing of current, in-flight transactions while recovery is running.
- **FR-006**: System MUST recompute the downstream aggregates whose values genuinely change as a result of the correction — principally `other_tax` in `daily_transaction_summaries`, and `sc_vat_exempt_sales` where alias variants apply. The system MUST NOT claim to correct aggregate components (VAT, vatable sales) that were already correct because they derive from `transactions` columns.
- **FR-007**: System MUST produce an auditable record of every correction made: which transaction, what tax records were added, when, and by which recovery run.
- **FR-008**: System MUST support validating a sample of reconstructed tax records against expected values before applying corrections at full scale.
- **FR-009**: System MUST make corrected tax data available to tenant-facing reports and dashboards for the defect window.
- **FR-009a**: System MUST identify which affected tenants cross the materiality threshold, defined as: for any affected reporting month, the tenant's **rendered** tax total changes by at least PHP 500 **or** at least 1% of that month's tax total (whichever triggers first). The `before` value MUST be the pre-backfill rendered aggregate captured per FR-012 — **not zero**. Using zero would compute deltas equal to the entire tax total and flag essentially every tenant for a restatement that largely did not occur.
- **FR-009b**: System MUST support notifying only those tenants identified in FR-009a. Tenants whose corrections fall below the threshold receive corrected reports without a proactive notification.
- **FR-010**: System MUST make corrected tax data available for BIR/CSMR-style compliance exports covering the defect window, such that an export regenerated for that period reflects the corrected figures.
- **FR-010a**: This feature MUST NOT itself determine whether any already-generated or already-submitted compliance filing must be resubmitted to BIR or any other authority. That decision is owned by finance/compliance stakeholders. The feature's obligation is to make corrected data and regeneration capability available to them.
- **FR-011**: System MUST reconstruct tax data at per-transaction, per-line-item fidelity — producing the individual tax records that would have been persisted had the defect never occurred — rather than correcting aggregate period totals only.
- **FR-011a**: Downstream summaries, dashboards, and exports MUST be regenerated from the corrected transaction-level records (per FR-006), not corrected independently of them, so that transaction-level data remains the single source of truth.
- **FR-012**: System MUST capture a pre-backfill snapshot of per-(tenant, reporting month) rendered aggregate totals, via the actual report path, before any mutation. This baseline is unrecoverable once the run begins and is the only defensible `before` value for materiality (FR-009a) and for proving no unintended aggregate drift (SC-003).
- **FR-012a**: The snapshot MUST record and pin the **rendering source label** (`daily_summary` vs `raw_transactions`). The two paths use different formulas and `SalesReportDataService` can flip between them on its own depending on `report_refresh_states` completeness — which this feature's own refresh mutates. Materiality (FR-009a) MUST refuse to compare a `before` and `after` captured under different sources.
- **FR-013**: System MUST archive the 3,238,180 NULL-keyed orphan rows to a durable, queryable store before deleting them, preserving `id`, `tax_type`, `amount`, and timestamps.
- **FR-014**: System MUST reconcile inserted rows against the still-present orphans **per day**, using **subset/residual semantics**, before deleting that day's orphans:
  - **Reconciled set**: for transactions successfully reconstructed that day, the inserted rows MUST reproduce the corresponding orphans' per-(`created_at` second, `tax_type`, `amount`) multiset **exactly**.
  - **Residual set**: the remaining orphans MUST be accounted for exactly — enumerated and equal to the unreconstructable transactions for that day (216 on 2026-06-13, zero on every other day). An unexplained residual MUST halt the run.
  - Equality over the *whole* day's orphan population is **NOT** the test — that would halt on 2026-06-13 by construction, where only 9 of 225 transactions are reconstructable.
  - This proves **content**, never **attribution**. Attribution rests solely on the post-fix oracle (FR-008). Both MUST pass; neither alone authorizes deletion.
- **FR-014a**: The run MUST be **pipelined per day** (insert day → reconcile day → delete day), not phase-wide. Inserting all ~3.24M rows before any reconciliation would surface a systematic reconstruction defect only after the entire population is written.
- **FR-015**: System MUST delete orphan rows only in bounded chunks, never in a single statement, and MUST NOT delete any row whose `transaction_pk` is non-null.
- **FR-015a**: System MUST use **insert-first ordering**: insert reconstructed linked rows, reconcile them against the still-present orphan population in situ, and only then delete the orphan subset whose replacement has been proven. Deletion before proven replacement is prohibited.
- **FR-015b**: **2026-06-13's orphan rows MUST be retained wholesale — the entire day, not a subset.** Orphans carry no linking column (research.md V4), so partitioning them into "belongs to a reconstructed transaction" vs "does not" is **not implementable**; correlating by contiguous id block and `created_at` was explicitly rejected as a heuristic on financial data. Day-level retention is therefore the only sound mechanism.
  - Consequence, accepted deliberately: the 9 reconstructable transactions on 2026-06-13 will carry **both** a linked and an orphan copy permanently (~36 over-retained rows). This is preferred over deleting the only surviving record of the other 216 transactions' tax lines.
  - All other days in the window have zero unreconstructable transactions, so their orphans are deleted in full once FR-014 passes for that day.
  - Retained rows MUST be marked `no_replacement_exists` and surfaced in the quarantine report. Their long-term disposition is a separate stakeholder decision outside this feature.
- **FR-016**: System MUST NOT treat the pre-backfill `other_tax` aggregate as a trustworthy currency figure while `SUM(tax_exempt)` (a boolean count) contributes to it. The feature MUST either exclude `other_tax` from exact-match assertions, or record the defect's contribution explicitly, and MUST state which in SC-003 and FR-009a. Fixing the underlying boolean-as-currency defect is out of scope.
- **FR-018 (BLOCKING)**: **Backfill is unsafe until row-level `other_tax` semantics are aligned with the PITX formula. Current accessor logic treats every non-VAT tax row as other tax, including `VATABLE_SALES`, which would collapse computed net values after reconstruction.** `Transaction::otherTaxSum()` sums all non-`VAT` rows; against the PITX formula (`Vatable Sales` is the base of GROSS, never a deduction) this is incoherent. It is inert only because defect-window transactions have no linked rows — reconstruction activates it, changing `net_amount`/`calculated_net_sales` on every API response for 809,107 transactions (worked arithmetic: 65.00 → 6.96, ≈89% collapse). **RESOLVED 2026-08-10 — Option 1, allow-list variant, with explicit VAT-exempt deduction (D7)** ([decision memo](decision-t088a-other-tax-semantics.md)): `otherTaxSum()` will be fixed to an allow-list counting only `OTHER_TAX`/`OTHER-TAX`; its `sc_vat_exempt_sales` column-fallback is removed and that deduction moves to the accessors as an **explicit separate term** (`gross − otherTaxSum() − scVatExemptSales`), preserving the PITX principle without the false PHP 13.8M movement; unknown tax types must be observable but never counted; `validateAmounts()` and `validateAmountReconciliation()` converge on one shared helper. **Scope honesty**: these accessors remain *not* full PITX NET SALES (promos, senior, PWD, employee discount and service charge are still omitted) — the accurate claim is that `other_tax` and VAT-exempt semantics are no longer conflated. Implementation of that fix is a prerequisite for reconstruction becoming visible. See also [other-tax-semantics.md](other-tax-semantics.md).
- **FR-017**: System MUST NOT run, and MUST document as prohibited during and after the backfill window, `backfill:transaction-aggregates --allow-write`. That command aggregates `transaction_taxes` by `transaction_pk` with `SUM` + broad `LIKE` matching and overwrites `transactions.vatable_sales`/`vat_amount`/`sc_vat_exempt_sales` — the exact columns SC-003 requires to remain unchanged. It is inert today only because the window has no linked rows; this feature arms it.

### Key Entities *(include if feature involves data)*

- **Transaction**: An ingested POS sale record. The entity whose tax breakdown is missing for the defect window; its underlying line-item and payload data is intact and is the source used to reconstruct tax records.
- **Transaction Tax**: A tax line item (e.g., VAT, VAT-exempt, other tax types) associated with a transaction. The entity being recovered.
- **Tenant**: The business whose transactions and reports are affected. Defines the scope boundary for the affected population (~87 tenants active during the defect window).
- **Correction Record**: An audit entity capturing what was changed by the recovery process, for traceability and dispute resolution (Story 3).
- **Reporting Aggregate**: Derived, cached, or pre-computed reporting data (daily/weekly/monthly summaries, dashboard figures) that reflected the incomplete tax data during the defect window and must be recomputed once underlying tax records are corrected.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of transactions within the confirmed defect window that had taxable line items have complete tax records after recovery.
- **SC-002**: Zero duplicate or double-counted tax records exist **among linked rows** after recovery, verified by an independent reconciliation pass. Qualification: ~900 NULL-keyed rows from 2026-06-13 are **retained permanently** by design (FR-015b), so any query that does not join on `transaction_pk` will continue to see them. "Zero orphans" is explicitly **not** a completion criterion; the correct post-run assertion is that the NULL-keyed count equals the retained residual, not zero.
- **SC-003**: Exactness is asserted at the level where it is meaningful: the `transaction_taxes` **row-level multiset per transaction** MUST match exactly (zero tolerance — this is replay of retained values). At the **aggregate** level, `other_tax` is excluded from exact assertion while the FR-016 boolean-as-currency defect persists; remaining components MUST match within a stated, written tolerance. Components already correct pre-backfill MUST be unchanged, verified against the pre-backfill snapshot (FR-012). Affected-tenant count to be confirmed by a distinct-tenant query over the window.
- **SC-004**: The recovery process completes with zero measurable disruption to concurrent live transaction ingestion (no failed or delayed live transactions attributable to the recovery running).
- **SC-005**: Every corrected transaction has a traceable audit entry that an internal user can retrieve on demand.
- **SC-006**: The set of tenants crossing the materiality threshold is reproducible — recomputing it from the recorded before/after totals yields the same set, so notification decisions can be defended after the fact.

## Assumptions

- **Confirmed 2026-08-10** (research.md R1/R2, V1a): the original submitted payload — including its tax lines — was retained for 99.97% of affected transactions; only the derived tax records failed to persist. Recovery is therefore replay of retained values, not recomputation.
- No tax-classification or calculation rules are applied during reconstruction. Tax amounts were always provider-submitted, never computed by TSMS, so no historical rule set is needed and none should be introduced.
- **Environment**: staging is the live pilot system carrying the affected tenants' real operational data. There is no separate production run in scope unless another deployment is later confirmed. The real run targets staging's live data; rehearsal is performed on a restored snapshot beforehand.
- **Defect window confirmed 2026-08-10** (research.md V1a): **2026-06-13 → 2026-08-10 ~10:00**, 59 days. The boundary is clean — 2026-06-12 shows 100% tax capture, 2026-06-13 shows 0%. Payload retention began on the same deployment that broke tax capture, so 808,891 of the 809,107 affected transactions (99.97%) are exactly recoverable; 216 (all on the 2026-06-13 transition day) are not and will be quarantined rather than reconstructed.
- Transactions that were voided or refunded during the defect window remain in scope for tax-record correction, subject to the same void/refund tax-handling rules applied to live transactions today.
- This feature is about restoring data correctness to existing reports, dashboards, and exports — it does not introduce new tenant-facing reporting features or UI.
- The materiality threshold in FR-009a (PHP 500 or 1% of the month's tax total, whichever triggers first) is a starting policy chosen to be deliberately inclusive — when the two tests disagree, the tenant is notified. Finance may tune either bound before rollout; doing so changes only which tenants are notified, not the correction itself.
- Notification content, channel, and timing are treated as a finance/comms decision to be settled during planning; this spec commits only to correctly identifying *who* qualifies and supporting the send.
- Restating already-delivered historical figures for materially-affected tenants is understood to be acceptable to the business; if any tenant contract or regulatory constraint forbids silent restatement, that surfaces as a planning-phase blocker rather than an assumption this spec can resolve.
