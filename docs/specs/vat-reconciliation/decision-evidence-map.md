# Decision-to-Evidence Mapping

Persisted copy of the mapping between `docs/specs/report-vat-correction-coverage.md`'s
seven unresolved Required Decisions and the queries in this package that produce
evidence for each. **Evidence, not conclusions** — none of these queries decide an
answer; they surface the data a human decision-maker needs.

| # | Required Decision | Evidence queries | What the evidence shows (not decides) |
|---|---|---|---|
| 1 | Correction methodology for bypass paths | A9, A11 | Whether an aggregate-only shortcut diverges materially from row-level correction, and the row volume a per-row approach would touch |
| 2 | Provider/tenant scope (config vs. heuristic vs. layered) | A10, B2/B3 | Whether the candidate VAT-basis flag is stable per provider across both months and terminals (A10's stddev + minimum-sample columns), or noisy |
| 3 | Historical cutover (clean boundary vs. none) | A4, A10 | Month-by-month drift per provider plus explicit first/last transition-month detection, without presupposing a split date |
| 4 | Backfill scope (recompute cached aggregates vs. forward-only) | A7, A11 | Cached-vs-live delta classified into named categories (not a single number), plus total historical volume |
| 5 | Consistency tolerance | A6 | Empirical delta distribution with population-coverage metadata, so low-confidence buckets are visible |
| 6 | Anomaly-query fate (keep vs. retire `DashboardService:154-155`) | A5, A5b, A8 | Overlap between the existing anomaly flag and the candidate basis flag; Goldilocks isolated via sensitivity analysis, never removed from the base picture |
| 7 | Alias handling policy (Track B) | B1 (manual opt-in), B2, B2b, B3, B4, B5 | Full alias inventory with per-surface recognition status, temporal vocabulary drift, and exact-vs-normalized unknown-alias distinction, from one shared alias-mapping fragment |

## How to use this table

For each Required Decision, run the listed evidence queries per `README.md`'s execution
order, record the results (and their execution-manifest entries), and bring the raw
evidence — not a pre-formed recommendation — to whoever is resolving that decision
(per `report-vat-correction-coverage.md`'s own note that this needs a finance/business
workshop plus a targeted technical reconciliation pass, not a unilateral engineering call).
