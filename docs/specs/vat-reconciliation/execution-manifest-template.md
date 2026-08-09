# Execution Manifest Template

Copy this template and fill in one manifest per evidence-gathering run. This prevents
evidence generated under different code or data states from being compared as though
it came from one controlled run. Store completed manifests alongside their output
(see `result-handling-and-redaction.md` for what may/may not be committed to Git).

```text
Run ID:                       <date>-<short-description>, e.g. 2026-08-10-kyukyu-smoke-test
Code commit SHA:               <git rev-parse HEAD at the time of the run>
Database environment/replica:  <e.g. reporting-replica-01, staging-snapshot-2026-08-09>
Schema / migration state:      <output of `php artisan migrate:status` tail, or migration batch number>
Execution timestamp (UTC):     <start> - <end>
Query file(s) run:             <e.g. 10-track-b-alias-discovery.sql, query B2>
Query file version:            <git blob hash or commit SHA the .sql file was read at>
Parameters used:
  :tenant_id                   <value, or "multiple — see below">
  :provider_id                 <value, if applicable>
  :date_from / :date_to        <values>
  :goldilocks_tenant_id        <value, if A8 was run>
Runtime config values recorded:
  tsms.reporting.exclude_voids_from_totals   <true/false — REQUIRED whenever A7 is run>
Row counts:                    <rows returned per query>
Duration:                      <wall-clock time per query>
EXPLAIN reviewed:               <yes/no — paste summary or note "full scan expected (manual opt-in query)">
Warnings / anomalies observed: <anything unexpected — timeouts, unexpected NULLs, etc.>
Output location:               <path or reference to where results were saved>
Operator:                      <who ran this>
Goldilocks exclusion applied:   <yes/no — if yes, confirm full-population results are ALSO retained per 70-goldilocks-sensitivity-analysis.sql's usage rule>
Session guards confirmed:      SET SESSION TRANSACTION READ ONLY; [ ]   MAX_EXECUTION_TIME set; [ ]   Reporting replica connection; [ ]
```

## Why this matters

- **Code drift**: `candidate_basis` (Fragment 0) is a direct SQL translation of
  `FinanceCalculationService::deriveMetrics()`. If that PHP method changes after a
  manifest's commit SHA, the fragment may silently diverge from the code it claims to
  reproduce — the commit SHA lets a reviewer check.
- **Data drift**: results from a run against a stale replica snapshot are not
  comparable to results from a fresh one without knowing which was which.
- **Config drift**: `tsms.reporting.exclude_voids_from_totals` is a runtime value, not
  something any static source read can confirm for a given moment — A7's whole
  "confirmed void-exclusion parity" claim in `60-track-a-backfill-scope-evidence.sql`
  is conditional on this value being recorded per run, not assumed.
- **Selective reporting risk**: recording whether the Goldilocks exclusion was applied,
  and requiring confirmation that full-population results are retained alongside it,
  guards against a sensitivity-analysis run accidentally becoming the only reported
  number.
