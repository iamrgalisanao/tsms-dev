# Deadlock Fix Refactor Checklist

## Purpose

This document is a concrete implementation checklist for reducing and preventing MySQL deadlocks in the transaction-ingestion flow under high concurrent load from multiple POS terminals.

It is based on the current behavior observed in the codebase:

* controller-level duplicate pre-checks before insert
* model-level duplicate checks in `Transaction.php`
* mutation of `transaction_id` inside model hooks
* large transaction scope in batch ingestion
* multiple unique indexes on the `transactions` table
* side effects occurring too close to the hot write path

---

## 1. Refactor objectives

### Primary goals

* Make transaction ingestion **atomic**.
* Keep DB transactions **as short as possible**.
* Move idempotency enforcement to the **database layer**, not application pre-checks.
* Eliminate **check-then-insert** race conditions.
* Prevent model hooks from changing the business identity of a transaction.
* Keep fixes **small, staged, and reversible**.

### Success criteria

* Deadlock count drops significantly under concurrent load.
* Duplicate submissions are handled as idempotent success instead of producing deadlocks.
* Batch ingestion remains reliable even when many terminals send transactions at the same time.
* Rollback path is clear if production behavior degrades.

---

## 2. Immediate code changes

## 2.1 Remove duplicate mutation logic from `Transaction.php`

### Action

Delete the `creating` hook logic that:

* checks whether `(terminal_id, transaction_id)` already exists
* mutates `transaction_id` by appending a suffix when a duplicate is detected

### Why

This adds extra reads on the hottest table during insert and still races under concurrency.
It also changes the transaction business key, which breaks idempotency expectations.

### Required change

Remove logic equivalent to:

```php
$exists = Transaction::where('terminal_id', $tx->terminal_id)
    ->where('transaction_id', $tx->transaction_id)
    ->exists();

if ($exists) {
    $tx->transaction_id = $tx->transaction_id . '-' . substr((string) microtime(true), -4);
}
```

### Keep or move separately

* If `customer_code` enrichment is still needed, do it in the service/controller before insert when possible.
* Avoid DB queries in model hooks for the hot ingestion path.

### Done when

* `Transaction.php` no longer queries `transactions` inside `creating`
* `transaction_id` is never auto-mutated by model hooks

---

## 2.2 Replace `first()/exists() + create()` with atomic write

### Action

Refactor transaction creation in both `storeOfficial()` and `batchStore()` so the system uses a single atomic write.

Preferred options:

* `upsert(...)` using the real business conflict key
* or `insert` with duplicate-key handling and no pre-check

### Recommended conflict key

Use the existing unique key:

```text
(terminal_id, transaction_id)
```

If tenant identity must be part of uniqueness in practice, revisit schema and standardize that first.

### Target service method

Create a dedicated ingestion service, for example:

```php
final class TransactionIngestService
{
    public function ingest(array $payload): array
    {
        return $this->withDeadlockRetry(function () use ($payload) {
            DB::table('transactions')->upsert(
                [$payload],
                ['terminal_id', 'transaction_id'],
                [
                    'updated_at',
                    'submission_uuid',
                    'submission_timestamp',
                    'payload_checksum',
                    'original_payload',
                    'validation_status',
                ]
            );

            $transaction = DB::table('transactions')
                ->where('terminal_id', $payload['terminal_id'])
                ->where('transaction_id', $payload['transaction_id'])
                ->first();

            return [
                'id' => $transaction->id,
                'transaction_id' => $transaction->transaction_id,
                'status' => 'accepted',
            ];
        });
    }
}
```

### Rules

* Do not `SELECT` first to decide whether to insert.
* Let the database decide whether the row already exists.
* Treat conflict as idempotent success where appropriate.

### Done when

* no controller path uses `first()` / `exists()` before transaction creation
* transaction creation happens through one shared service

---

## 2.3 Add deadlock retry around the atomic DB write only

### Action

Wrap the smallest possible transaction block with retry logic for:

* deadlock
* serialization failure
* lock wait timeout

### Example

```php
private function withDeadlockRetry(callable $callback, int $maxAttempts = 5)
{
    $attempt = 0;

    retry:
    try {
        return DB::transaction($callback, 1);
    } catch (\Illuminate\Database\QueryException $e) {
        $attempt++;

        $message = $e->getMessage();
        $retryable =
            str_contains($message, 'Deadlock found when trying to get lock') ||
            str_contains($message, 'SQLSTATE[40001]') ||
            str_contains($message, 'Lock wait timeout exceeded');

        if ($retryable && $attempt < $maxAttempts) {
            usleep(random_int(50_000, 150_000) * $attempt);
            goto retry;
        }

        throw $e;
    }
}
```

### Rules

* Retry only retryable DB concurrency errors.
* Do not retry validation errors or permanent constraint violations.
* Use jitter to avoid synchronized retries from many workers.

### Done when

* all ingestion writes use the same retry helper or middleware
* retries are measurable via logs/metrics

---

## 2.4 Shrink batch transaction scope

### Action

Refactor `batchStore()` so the system does **not** hold one DB transaction around the entire batch.

### Replace this pattern

* begin transaction once
* loop through many incoming transactions
* validate, read, insert, log, update terminal, dispatch jobs in one long transaction

### With this pattern

* validate request payload outside DB transaction
* process each item independently
* open a tiny DB transaction only for the single transaction write
* dispatch jobs `afterCommit()`
* collect per-item result into batch response

### Target shape

```php
$results = [];

foreach ($transactions as $input) {
    try {
        $payload = $this->normalizeTransactionPayload($input, $terminal);
        $result = $transactionIngestService->ingest($payload);

        ProcessTransactionJob::dispatch($result['id'])->afterCommit();

        $results[] = [
            'transaction_id' => $result['transaction_id'],
            'status' => 'accepted',
        ];
    } catch (\Throwable $e) {
        $results[] = [
            'transaction_id' => $input['transaction_id'] ?? null,
            'status' => 'failed',
            'error' => $e->getMessage(),
        ];
    }
}
```

### Why

Smaller transaction windows reduce lock duration and dramatically lower deadlock probability.

### Done when

* batch request can partially succeed without rolling back all items
* each item is handled independently

---

## 2.5 Move side effects off the hot path

### Action

Review all work that happens immediately before or after transaction creation.

Candidates to move away from the hot insert path:

* terminal heartbeat updates
* cache invalidation
* metrics counter updates
* non-critical logs
* downstream processing jobs
* expensive payload transformation

### Preferred approach

* perform only the minimum DB write in the hot path
* dispatch non-critical work asynchronously after commit

### Example

```php
DB::afterCommit(function () use ($transactionId) {
    ProcessTransactionJob::dispatch($transactionId);
    UpdateTerminalHeartbeatJob::dispatch($terminalId);
});
```

### Done when

* insert path performs only essential writes
* non-critical post-processing is async

---

## 2.6 Remove schema inspection from hot path

### Action

Remove runtime `Schema::hasColumn(...)` checks from request-time transaction ingestion logic.

### Why

Schema inspection is unnecessary overhead in a high-throughput hot path and complicates logic.

### Replacement options

* rely on migrations and a stable schema
* use config flags if compatibility handling is temporarily needed
* isolate backward-compatibility logic outside the ingest path

### Done when

* hot ingestion code no longer checks schema metadata during request processing

---

## 3. Schema and index review checklist

## 3.1 Review unique constraints and choose the primary idempotency key

### Current candidates

* `UNIQUE (terminal_id, transaction_id)`
* `UNIQUE (submission_uuid)`
* `UNIQUE (tenant_id, terminal_id, receipt_no, transaction_date)`

### Action

Agree on the exact meaning of each constraint:

* `submission_uuid` = submission envelope idempotency
* `(terminal_id, transaction_id)` = transaction identity
* `(tenant_id, terminal_id, receipt_no, transaction_date)` = business rule uniqueness only if truly required

### Decision rule

Each unique index should represent a necessary and clearly documented business rule.
If two unique keys are enforcing overlapping concepts, simplify.

---

## 3.2 Remove redundant non-unique indexes

### Action

Review and possibly remove redundant indexes that increase insert cost.

### Likely candidates

* duplicate lookup index on `submission_uuid`
* overlapping receipt lookup indexes that may be covered by a wider index already

### Safety rule

Before dropping an index:

* inspect real query plans in staging/production
* verify no critical query depends on the exact index
* drop one at a time with rollback-ready migration

### Done when

* only necessary indexes remain on `transactions`
* insert path no longer touches unnecessary secondary indexes

---

## 4. Controller refactor checklist

## 4.1 `storeOfficial()`

### Replace current behavior

* duplicate pre-check
* create
* catch duplicate
* fetch again

### With

* normalize payload
* call `TransactionIngestService::ingest()`
* return accepted/idempotent result
* schedule async processing after commit

### Checklist

* [ ] no `first()` / `exists()` before transaction creation
* [ ] no `Transaction::create()` directly from controller hot path
* [ ] no duplicate handling based on model hook side effects
* [ ] controller delegates persistence to service

---

## 4.2 `batchStore()`

### Replace current behavior

* one large transaction for the whole batch
* per-item duplicate reads
* per-item creates inside wide transaction scope

### With

* independent per-item processing
* per-item atomic DB write
* partial success response model

### Checklist

* [ ] outer batch DB transaction removed
* [ ] each item processed independently
* [ ] per-item failures do not roll back the entire batch
* [ ] background jobs dispatched after commit

---

## 5. Model refactor checklist

## `Transaction.php`

### Keep only if truly needed

* simple casts
* relationships
* lightweight attribute defaults

### Remove from hot write lifecycle

* duplicate existence checks
* transaction ID mutation
* heavy DB lookups in `creating`
* expensive side effects in `created` / `updated` if those can move to events/jobs

### Preferred replacement

Use domain service or listener classes instead of model hooks for ingestion-specific logic.

### Checklist

* [ ] `creating` contains no duplicate lookup on `transactions`
* [ ] `creating` does not mutate `transaction_id`
* [ ] post-write side effects are async or minimal

---

## 6. Concurrency test plan

## 6.1 Unit/service tests

### Add tests for

* duplicate submission of same `(terminal_id, transaction_id)`
* repeated submission with same `submission_uuid`
* idempotent success behavior
* deadlock retry helper behavior on simulated retryable exception

### Checklist

* [ ] service returns stable result on repeated identical payload
* [ ] business key remains unchanged
* [ ] duplicate path does not create extra transaction rows

---

## 6.2 Integration/load tests

### Simulate

* many POS terminals sending transactions concurrently
* duplicate deliveries from same terminal
* multiple terminals posting at high frequency per second
* bursts with overlapping receipt numbers and submission UUIDs

### Key measurements

* deadlocks/minute
* duplicate-key conflicts/minute
* insert latency P50/P95/P99
* queue lag
* DB CPU and lock waits

### Checklist

* [ ] load test covers at least concurrent inserts for same terminal
* [ ] load test covers concurrent inserts across many terminals
* [ ] metrics collected before and after refactor

---

## 7. Observability changes

## 7.1 Structured logging

### Add structured fields to retry and conflict logs

* `tenant_id`
* `terminal_id`
* `transaction_id`
* `submission_uuid`
* `receipt_no`
* `retry_attempt`
* `error_code`
* `job_id` or request correlation ID

### Why

This makes it possible to confirm whether deadlocks are tied to duplicate submissions, specific terminals, or specific ingestion flows.

---

## 7.2 Metrics to add

### Application metrics

* transaction ingest attempts
* idempotent accepts
* duplicate-key errors
* deadlock retries
* deadlock failures after max retry
* processing time per endpoint

### Database metrics

* lock waits
* deadlocks
* rows inserted/sec
* rows updated/sec

---

## 8. Version control and rollback strategy

## 8.1 Branching strategy

### Recommended branches

* `main` or `master` = stable production branch
* `release/<date-or-version>` = release preparation branch
* `feature/deadlock-refactor-phase-1`
* `feature/deadlock-refactor-phase-2`
* `feature/deadlock-refactor-phase-3`

### Why

Do not ship all deadlock fixes in one giant branch.
Stage them so each step can be validated and reverted independently.

---

## 8.2 Suggested commit plan

### Phase 1: safe code-path cleanup

Commit separately:

1. remove duplicate mutation from `Transaction.php`
2. add deadlock retry helper
3. add structured logs and metrics

### Phase 2: controller/service refactor

Commit separately:
4. introduce `TransactionIngestService`
5. refactor `storeOfficial()` to use service
6. refactor `batchStore()` to per-item atomic processing

### Phase 3: schema/index cleanup

Commit separately:
7. add migration to remove redundant indexes
8. optional follow-up migration to simplify constraints if approved

### Rules

* one concern per commit
* each commit must compile and pass tests
* avoid mixing schema changes with large controller refactors in the same commit

---

## 8.3 Tagging strategy

### Before rollout

Create a release tag from the last known good version:

```bash
git tag pre-deadlock-fix-<YYYYMMDD>
git push origin pre-deadlock-fix-<YYYYMMDD>
```

### After each rollout phase

Tag each deployable milestone:

```bash
git tag deadlock-fix-phase1-<YYYYMMDD>
git push origin deadlock-fix-phase1-<YYYYMMDD>
```

### Why

Tags provide a fast rollback anchor that is easier to communicate and safer to deploy from than ad hoc commit hashes.

---

## 8.4 Deployment strategy

### Recommended rollout sequence

1. deploy observability and retry helper first
2. deploy model cleanup next
3. deploy service/controller refactor next
4. deploy index cleanup last

### Why

This sequence lets the team observe improvement gradually and isolate regressions.

### Rollout recommendation

Use one of these:

* canary deployment for a subset of tenants or terminals
* blue/green deployment if supported
* feature flag for new ingestion service path

---

## 8.5 Feature-flag strategy

### Add a feature flag

Example flags:

* `transactions.atomic_ingest.enabled`
* `transactions.batch_small_tx.enabled`
* `transactions.disable_model_duplicate_mutation`

### Why

Feature flags allow fast rollback without immediate code revert.

### Rollback path

If production behavior degrades:

* disable the new ingest path flag
* route traffic back to previous stable path temporarily
* investigate before reverting code

---

## 8.6 Revert strategy

### Code rollback

If a deployment causes errors:

1. disable feature flags if available
2. revert the last deployment commit or release tag
3. redeploy the previously tagged stable version

### Database rollback

For index drops or schema changes:

* every migration must have a tested `down()` method
* do not combine irreversible schema changes with code-path rollout in the same release
* schedule index changes in a separate deployment window

### Required preparation

* verify restore procedures for DB backups
* ensure migration rollback is tested in staging
* document exact rollback commands in release notes

---

## 8.7 Pull request checklist

### Every PR should answer

* What concurrency problem does this change solve?
* Does this change shorten or widen transaction scope?
* Does this change add or remove DB reads on the hot write path?
* Can this PR be rolled back independently?
* Is there a feature flag or tag for safe rollback?

### PR approval checklist

* [ ] tests added or updated
* [ ] no new check-then-insert pattern introduced
* [ ] no model hook mutates transaction identity
* [ ] metrics/logging included where needed
* [ ] rollback plan documented in PR description

---

## 9. Recommended implementation order

### Phase 1

* [ ] add retry helper
* [ ] add structured deadlock and duplicate metrics
* [ ] remove duplicate mutation from `Transaction.php`

### Phase 2

* [ ] introduce `TransactionIngestService`
* [ ] refactor `storeOfficial()` to atomic write
* [ ] refactor `batchStore()` to small per-item transactions

### Phase 3

* [ ] move side effects off hot path
* [ ] remove `Schema::hasColumn(...)` from ingestion flow
* [ ] optimize model hooks or replace with listeners/jobs

### Phase 4

* [ ] review and remove redundant indexes
* [ ] validate query performance after each index change

### Phase 5

* [ ] run concurrency/load test
* [ ] compare before/after deadlock metrics
* [ ] deploy with tag and rollback plan ready

---

## 10. Final implementation standard

The ingestion standard for this system should be:

* one transaction payload enters the system
* one atomic DB write decides if it is new or already known
* the business key is never mutated by model hooks
* the DB transaction is as short as possible
* all non-essential work happens after commit
* every release is tagged and rollback-ready

This should become the baseline rule for any future transaction-ingestion endpoint or background job.
