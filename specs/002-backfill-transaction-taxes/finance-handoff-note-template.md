# Finance/Compliance Handoff Note — Tax-Backfill Correction (T063)

> **STATUS: TEMPLATE ONLY — not a completed handoff note.**
> No real `--apply` backfill run has occurred yet (as of 2026-08-15, still
> pre-rehearsal). Every bracketed placeholder below must be filled from the
> actual run's output once a real `--apply` run has completed and its
> materiality/verdict data exists. Do not send this note to finance/compliance
> until every placeholder is replaced with real figures and this status line
> is removed. See `tasks.md` T063 for the parent task; this file is not
> checked off `[x]` there — it is marked "template prepared; final handoff
> pending real run/materiality output."

---

## 1. What was corrected

A code defect (fixed [FIX_DATE], confirmed via 100% capture rate on new
transactions since) caused every ingested POS transaction's tax line items
(`transaction_taxes` rows — VAT, VAT-exempt, other tax types) to be silently
dropped system-wide for transactions ingested during the defect window below.
The underlying transaction data itself (gross sales, line items, tax
classifications) was intact; only the derived tax-breakdown rows failed to
persist.

This backfill reconstructed and inserted the missing `transaction_taxes` rows
from each transaction's original payload, without modifying any other
already-correct data (`vat_amount`, `vatable_sales`, `sc_vat_exempt_sales` on
`transactions` were correct throughout and were not touched).

## 2. Affected window

- **Start**: [WINDOW_START] — [confirm exact start date from deployment
  history / preflight evidence, per research.md]
- **End**: [WINDOW_END] (defect fix confirmed live)
- **Total transactions in window**: [TOTAL_TRANSACTIONS]
- **Recoverable (backfilled)**: [RECOVERABLE_COUNT]
- **Unrecoverable (quarantined — no replacement possible)**: [QUARANTINED_COUNT]
  — see `transactions:tax-backfill-show --quarantined` for the reviewable
  list (reason codes: `missing_payload`, `cross_check_mismatch`)

## 3. Run identification

- **Run ID(s)**: [RUN_ID or list, if resumed/re-run]
- **Applied at**: [APPLY_TIMESTAMP]
- **Verified via**: `transactions:tax-backfill-readiness-verdict` — verdict:
  [PASS/WARN], run at [VERDICT_TIMESTAMP]
- **Materiality report**: `transactions:tax-backfill-materiality-report`
  snapshot [SNAPSHOT_RUN_ID]

## 4. Per-tenant materiality list

> Pull directly from the materiality report's persisted before/after/delta
> evidence (T047/Command 3) rather than recomputing by hand — the report is
> the system of record for these figures.

| Tenant ID | Tenant Name | Transactions Corrected | `other_tax` Before | `other_tax` After | Delta | Materiality Flag |
|-----------|-------------|------------------------|---------------------|--------------------|-------|-------------------|
| [ID] | [NAME] | [COUNT] | [BEFORE] | [AFTER] | [DELTA] | [FLAG] |

**Note on `other_tax` figures**: `other_tax_before`/`other_tax_after` each
individually include a boolean-summed `transactions.tax_exempt` contribution
(a pre-existing, out-of-scope defect, FR-016) and must not be read as clean
standalone currency figures on their own. The delta between them is
unaffected by this, since the backfill never modifies `transactions.tax_exempt`.

**Total tenants materially affected**: [MATERIAL_TENANT_COUNT] of ~87 active
in the window.

## 5. Reports and exports regenerated

List every downstream report/dashboard/export that was regenerated against
the corrected data, and confirm each now reflects accurate figures for the
window:

- [ ] Tenant-facing sales/finance reports — [regenerated via: ...]
- [ ] BIR/CSMR compliance exports — [regenerated via: ...]
- [ ] Dashboard aggregates (`daily_transaction_summaries`, hourly/weekly
      rollups) — [refreshed via: `ReportingRefreshCommand` / job names]

## 6. Validation evidence

Summarize results from T058–T061 (coverage by date, by tenant, by tax type,
by totals) once that validation suite exists and has been run against this
backfill. [Link to validation command output / report.]

## 7. Compliance decision — explicitly out of this feature's scope

**This feature does not determine, and has not determined, whether any
already-submitted compliance filing must be resubmitted to BIR or any other
authority.** That decision belongs to finance/compliance stakeholders alone
(FR-010a). This feature's obligation ends at making corrected data and
regeneration capability available — the sections above are provided so
finance/compliance can make that determination with complete, accurate
information.

## 8. Contacts / questions

[Engineering contact for questions about how the correction was performed —
not for re-filing decisions, which are finance/compliance's own call.]
