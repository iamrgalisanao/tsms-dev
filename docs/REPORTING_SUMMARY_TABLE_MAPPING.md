# Reporting Summary Table → Webapp Mapping

This document maps the recommended summary tables to the existing webapp report pages, indicates the primary metrics required, freshness expectations, and the controller/endpoints to update.

Format: Page / Route → Summary table(s) → Key metrics → Freshness → Where to change

---

Finance — Transaction Logs
- Route: `finance.transaction-logs` (view: `transaction_logs.blade.php`)
- Summary tables: `transactions_hourly`, `validation_status_summary_hourly` (KPIs)
- Metrics: gross/net, discounts, taxes, validation_status counts, per-tenant totals
- Freshness: near-real-time KPIs (1–5m). Detailed drill-down uses raw `transactions`.
- Change: `FinanceController` action — KPIs read from `DB::connection('reporting')->table('transactions_hourly')`

Finance — Transactions (list)
- Route: `finance.transactions` (view: `transactions.blade.php`)
- Summary tables: `transactions_daily` or `transactions_hourly` for list aggregates
- Metrics: gross/net by date/tenant, VAT, refunds
- Freshness: hourly for aggregates; raw data for individual rows
- Change: update list-aggregate endpoints to use reporting connection

Finance Reports — Certified Monthly Sales
- Route: `finance.reports` (view: `reports.blade.php`)
- Summary tables: `transactions_monthly`, optionally `transactions_daily` for drilldown
- Metrics: certified totals, tenant/month totals, reconciled flags
- Freshness: daily finalization; monthly certification job
- Change: `SalesReportExportController` → read from `transactions_monthly`

Statement of Accounts (SOA)
- Route: `finance.soa` (view: `soa.blade.php`)
- Summary tables: `transactions_daily`, `reconciliation_summary_daily`
- Metrics: date/tenant amount, paid/due, mismatch flags
- Freshness: nightly

Sales-report (import/export) controllers
- Controllers: `FinanceController`, `SalesReportExportController`
- Summary tables: `transactions_daily`, `transactions_monthly` for templates & exports
- Note: Upload/preview flows should validate against raw `transactions` or `reconciliation_summary_daily`

Commercial reports (hourly/daily/weekly/monthly/yearly)
- Routes/Views: `commercial.sales-report.*` / `daily-sales-report.blade.php`, etc.
- Summary tables: `transactions_hourly`, `transactions_daily`, `transactions_monthly`, `payment_method_summary_*`, `currency_summary_*`
- Metrics: tx_count, total_amount, avg_ticket, payment_method breakdowns
- Freshness: hourly (1–5m) for operational; nightly/daily for historical

Tenant profile / Sales & Rent analysis
- Route: `commercial.tenant.show` (view: `tenant-profile.blade.php`)
- Summary tables: `transactions_daily`, `transactions_monthly`, `terminal_activity_hourly`, `payment_method_summary_*`
- Freshness: daily/ hourly

Executives / Tenant dashboards
- Routes/Views: `executives.*`, `tenant.reports`, dashboards
- Summary tables: compiled KPIs from `transactions_*` rollups and `validation_status_summary_hourly`
- Freshness: cached (5–30m) for dashboards

IT Support / Ops pages
- Routes: `it-support.logs`, `it-support.health`, `it-support.alerts`
- Summary tables: `terminal_activity_hourly`, `anomalies`, `submission_event_summary`
- Freshness: near-real-time for alerts, hourly for trends

Export controllers (Excel)
- Use `transactions_monthly` / `transactions_daily` for fast generation. Keep export logs and include checksums for audit.

---

Notes
- Controllers that serve aggregates should use the `reporting` DB connection (see implementation plan) and include tenant scoping.
- Keep raw `transactions` as the audit source of truth; summary tables are for fast reads and analytics.
