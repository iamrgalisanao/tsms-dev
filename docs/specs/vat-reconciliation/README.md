# VAT Correction Reconciliation Evidence Package

Supporting artifact for `docs/specs/report-vat-correction-coverage.md`'s seven unresolved
Required Decisions. This directory contains **SQL/query artifacts and documentation only** —
gathering evidence, not implementing a fix.

## Status and terminology

**Candidate calculations reproduce the currently proposed correction methodology for
evidence gathering only. They are not an approved accounting rule or production
implementation.** Every query and column in this package uses "candidate" / "heuristic"
language deliberately — never "canonical" — because `report-vat-correction-coverage.md`
remains in Draft status. Nothing here may be read as an accepted formula, an approved
scope, or a sanctioned cutover date. That approval happens separately, per the parent
spec's "Required Decisions" section, after this evidence is reviewed.

## Scope (binding for every file in this directory)

- SQL/query artifacts only
- No application behavior changes
- No schema changes
- No backfill
- No production mutation
- Read-only, end to end — every file in this package is `SELECT`-only; none contains
  `UPDATE`/`DELETE`/`INSERT`/`CREATE`/`ALTER`/`DROP`

## Required operational safeguards

Before running **any** query in this package against a real database:

```sql
SET SESSION TRANSACTION READ ONLY;
SET SESSION MAX_EXECUTION_TIME = 30000; -- milliseconds; lower for exploratory runs, raise only with sign-off
```

- Run `EXPLAIN <query>` before running the query for real. If `EXPLAIN` shows a full table
  scan on `transactions` or `transaction_taxes` for a query not explicitly marked
  **MANUAL OPT-IN** below, stop and re-check the bound parameters — that query is not
  supposed to scan the whole table.
- Execute against the reporting read-replica connection (per `docs/HORIZON_REPORTING_SETUP.md`'s
  existing pattern for heavy read-only reporting workloads), never the primary write connection.
- Every query in `10-*.sql` through `70-*.sql` requires `:tenant_id` (or, for A4/A10, an
  explicit cross-tenant justification — see those files) and `:date_from`/`:date_to` as
  **named bind placeholders with no `IS NULL OR` escape hatch**. A prepared-statement driver
  (PDO / Laravel `DB::select()`) raises an error if a named placeholder in the SQL text has
  no bound value — this is the actual enforcement mechanism, not a convention that can be
  silently skipped.
- Queries in `90-unscoped-manual-opt-in-only.sql` are **never** part of the default execution
  sequence. They require explicit operator sign-off, must run off-peak, against the replica,
  and are the only files in this package permitted to omit tenant/date scoping.
- Record duration, `EXPLAIN` output, and row counts for every query as it runs into an
  execution manifest — see `execution-manifest-template.md`. Do not reconstruct this after
  the fact.

## File layout

| File | Contents |
|---|---|
| `00-shared-fragments.sql` | Fragment 0 (`candidate_basis`, with its full Inputs/Outputs/Non-goals contract) and Fragment 0b (`known_tax_type_aliases`) — the single source of truth every other file builds on |
| `10-track-b-alias-discovery.sql` | B2, B2b, B3, B4, B5 — tax-type alias inventory, drift, and cross-surface classification delta |
| `20-track-a-cross-surface-comparison.sql` | A1, A2, A3, A9 — per-transaction and aggregate raw-vs-candidate comparisons |
| `30-track-a-tolerance-distribution.sql` | A6 — empirical delta-bucket distribution |
| `40-track-a-mixed-rollout-detection.sql` | A4, A10 — month/provider/terminal drift and consistency detection |
| `50-track-a-anomaly-query-analysis.sql` | A5, A5b — reproduction of the existing `DashboardService` anomaly query and its overlap with the candidate flag |
| `60-track-a-backfill-scope-evidence.sql` | A7, A11 — cached-vs-live reconciliation and historical volume sizing |
| `70-goldilocks-sensitivity-analysis.sql` | A8 — sensitivity analysis only; never replaces full-population results |
| `90-unscoped-manual-opt-in-only.sql` | B1, `historical_volume_sizing_all_tenants` — full-history/unscoped queries, gated |
| `decision-evidence-map.md` | Persisted copy of the Decision-to-Evidence table |
| `execution-manifest-template.md` | Template to fill in for every evidence-gathering run |
| `result-handling-and-redaction.md` | Rules for what may leave this repository in exported evidence |

## Execution order

0. Preflight every session: `SET SESSION TRANSACTION READ ONLY;`, `MAX_EXECUTION_TIME` guard,
   confirm the reporting-replica connection, open a new execution-manifest record.
1. `10-*.sql` B2 on one known-good tenant (Kyukyu — the tenant `report-vat-correction-coverage.md`
   confirms `deriveMetrics()` already reconciles correctly for) — smoke-test the join shape.
2. `10-*.sql` B2b, B3, B4, B5 — widen Track B alias discovery by tenant/provider.
3. `60-*.sql` A11 (tenant-scoped) — size historical volume before row-level work.
4. `20-*.sql` A1, A2 on the same known-good tenant — validate `candidate_basis` against a
   tenant already known to reconcile.
5. `20-*.sql` A3, A9 — cross-surface and aggregate-shortcut comparisons, then widen tenants.
6. `50-*.sql` A5, A5b — anomaly-query reproduction and overlap analysis.
7. `30-*.sql` A6 — tolerance distribution, widened across tenants/providers.
8. `40-*.sql` A4, A10 — mixed-rollout and provider/terminal consistency (heaviest per-provider
   aggregations; run only after step 4–5 validate the fragment).
9. `60-*.sql` A7 — cached-vs-live backfill sizing, after confirming `report_refresh_states`
   freshness **and** recording the live `tsms.reporting.exclude_voids_from_totals` config value.
10. `70-*.sql` A8 — always run and reported alongside the full-population numbers from steps
    2–9, labeled "sensitivity analysis," never replacing them.
11. `90-*.sql` — manual opt-in only, off-peak, replica, explicit operator sign-off. Never part
    of the default sequence.

## Result handling

See `result-handling-and-redaction.md` before exporting or sharing any output from this
package outside this repository.
