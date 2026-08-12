# Slice 18 Brief — T077: Connection-Identity Recording for Aggregate Refresh

**Status:** Draft — awaiting architect (user) sign-off before implementation, per this feature's established brief-first convention for high-risk slices.

## Task

T077 ([tasks.md](tasks.md#L235)):

> **Record** the aggregating connection's resolved `@@server_id`/`DATABASE()` values in the run record (not merely assert equality against config) — `config/database.php:59-75`'s `reporting` connection falls back to the primary's env vars per-field, and `.env.example` ships those blank, so in an environment that hasn't set them an equality *assertion* passes vacuously without proving anything. Implement a `MASTER_POS_WAIT` gate **only if** the recorded values differ from the primary's. **Supersedes T038** — both refresh commands aggregate on the primary, so the original replica-lag blocker was aimed at a hazard that largely does not exist (Architect F4).

Referenced by `live-run-readiness-plan.md` §3.7 as a **hard blocker on the live run itself** (not just rehearsal): the post-backfill aggregate refresh step currently has no recorded proof it ran against the primary.

## Grounding (verified against current code, not inherited)

- `RefreshDailyTransactionSummaries::handle()` (`app/Console/Commands/RefreshDailyTransactionSummaries.php`) never calls `DB::connection(...)` — every query and the final `DB::transaction(...)` write uses the bare `DB::` facade, i.e. whichever connection `config('database.default')` resolves to at runtime. Grepped directly, confirmed zero `DB::connection(` calls in this file.
- `ReportingRefreshCommand` (`reporting:refresh transactions_hourly|transactions_daily`) does call `DB::connection('reporting')`, but only to resolve a database *name* for a fully-qualified `` `db`.table `` insert target — the actual `DB::statement($sql)` still executes on the default connection. Per T082 (already applied), `transactions_hourly`/`transactions_daily` derive tax figures from `transactions` columns directly, not from `transaction_taxes` rows, so they are **not genuinely affected** by this backfill and are out of scope for T077's gate.
- `daily_transaction_summaries` (written by `RefreshDailyTransactionSummaries`, keyed by `other_tax` among other columns sourced from `transaction_taxes`) is the one table this feature's FR-012 snapshot (Slice 15) and downstream CMSR/dashboard reads actually depend on. **T077's scope is this one command.**
- `report_refresh_states` (same migration as `daily_transaction_summaries`) is already upserted once per `(report_type, tenant_id, business_date)` on every refresh call — this is the existing "run record" data-model.md's Architect F4 note refers to ("recorded in the run record"). It currently has no identity columns.
- The hazard isn't "this command deliberately reads a replica" (Architect F4 already disproved that) — it's that `config('database.default')` is env-driven (`DB_CONNECTION`) and nothing today would catch a misconfiguration (e.g. someone points default at a read replica or the wrong schema) before this command silently aggregates and writes against it. An equality *assertion against config* is vacuous per the task's own wording (`.env.example` ships the `reporting` connection's per-field overrides blank, so config-vs-config comparisons can pass without proving anything about the live connection). Only a runtime query against the connection actually in use proves anything.

## Design

**Scope: `RefreshDailyTransactionSummaries` only.** `ReportingRefreshCommand` is not touched (out of scope per T082's own established finding above).

### 1. `ConnectionIdentityResolver` (new, `app/Services/Backfill/ConnectionIdentityResolver.php`)

```php
interface ConnectionIdentityResolver
{
    /** @return array{server_id:int, database:string} */
    public function resolve(string $connectionName): array;
}

class DatabaseConnectionIdentityResolver implements ConnectionIdentityResolver
{
    public function resolve(string $connectionName): array
    {
        $row = DB::connection($connectionName)->selectOne('SELECT @@server_id AS server_id, DATABASE() AS db_name');
        return ['server_id' => (int) $row->server_id, 'database' => (string) $row->db_name];
    }
}
```

Bound in a service provider (or resolved via `app(ConnectionIdentityResolver::class)` with the interface bound to the concrete class in `AppServiceProvider`) so it's swappable in tests without needing a second physical MySQL database.

### 2. Gate in `RefreshDailyTransactionSummaries::handle()`

Immediately after the existing table-existence check, before any aggregation query:

```php
$aggregating = $this->identity->resolve(config('database.default'));
$primary = $this->identity->resolve('mysql'); // literal, canonical primary connection name

if ($aggregating !== $primary) {
    $this->error('Aggregating connection identity does not match the primary connection...');
    Log::error('reports:refresh-daily-transaction-summaries: connection identity mismatch', [
        'aggregating' => $aggregating,
        'primary' => $primary,
    ]);
    return self::FAILURE; // zero writes — refuses before the aggregation query even runs
}
```

`'mysql'` is hardcoded as the literal, canonical-primary connection name (per `config/database.php`'s own structure — `reporting` explicitly falls back to `mysql`'s env vars, confirming `mysql` is this app's one designated primary connection entry regardless of what `database.default` happens to be set to). **Flagging this as an explicit assumption for confirmation** — if a future deployment renames or multiplies primary connections, this hardcoded name would need to change too.

### 3. Recording (only on the match/proceed path)

Extend `report_refresh_states` with two new nullable columns (migration `2026_08_13_000001_add_connection_identity_to_report_refresh_states.php`):
- `server_id` (unsigned int, nullable)
- `database_name` (string, nullable)

Populate them from `$aggregating` on every `updateOrInsert` call in the existing `foreach ($affectedDates as $date)` loop — i.e. every refresh run, not just backfill-related ones, since this is a permanent operational-safety improvement to a command that already runs on a schedule every 15 minutes (`routes/console.php`), not a backfill-only concern.

### 4. `MASTER_POS_WAIT` — proposed scope decision, needs your sign-off

The task says "implement a `MASTER_POS_WAIT` gate only if the recorded values differ." Building an actual replication-catch-up mechanism (parsing `SHOW MASTER STATUS`/`SHOW SLAVE STATUS`, polling with a timeout policy) would be a materially larger, riskier piece of new infrastructure with no existing pattern in this codebase, for a hazard Architect F4 already found "largely does not exist" in practice (both refresh commands aggregate on the primary today).

**Proposed:** treat the gate as **fail-closed, not fail-and-wait** — on mismatch, refuse and log full detail (both identity tuples) for operator investigation, exactly as shown in §2 above. A genuine mismatch here means something is actually misconfigured (wrong `DB_CONNECTION`, wrong schema), not ordinary replication lag on a known-good topology — the safe response is a human looking at it, not an automated wait loop guessing at a replication topology this codebase has no model of.

If you'd rather I build the literal wait-and-retry mechanism instead, say so and I'll scope a follow-up brief for it — but I'd recommend fail-closed as the correct scope for this slice.

## Not in scope

- `ReportingRefreshCommand`/`transactions_hourly`/`transactions_daily` (confirmed non-tax-affected, T082).
- Any actual replication-lag wait/retry mechanism (see §4 — pending your decision).
- T076, T054's drill, materiality — unrelated, separately deferred.

## Tests

- Pure unit test on the comparison logic (no DB needed): identical tuples → proceeds; any single-field difference (`server_id` differs, or `database` differs, or both) → refuses.
- Feature test with the real resolver: normal test-env run (default connection *is* `mysql` in this app's config) records `server_id`/`database_name` on `report_refresh_states` matching a live `SELECT @@server_id, DATABASE()` oracle query, and `daily_transaction_summaries` is written as before (no behavior regression).
- Feature test with a bound fake `ConnectionIdentityResolver` returning divergent tuples per connection name argument: command returns `FAILURE`, zero rows written to `daily_transaction_summaries` or `report_refresh_states` for the run, error logged with both tuples.

## Verification plan

Implement → targeted tests (`--filter=RefreshDailyTransactionSummaries` or similar) → full `--filter=Backfill` regression → Pint → Code Reviewer → fix findings → re-verify → Architect drift-revalidation (this touches a scheduled, already-live command with financial aggregation, so it stays on the High-Risk Gates list) → update `tasks.md`/`live-run-readiness-plan.md` → commit.
