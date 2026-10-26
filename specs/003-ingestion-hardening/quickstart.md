# Quickstart: validating Ingestion Hardening

## Prerequisites

```bash
docker start tsms-test-mysql
DB_DATABASE=tsms_db_test php artisan migrate:fresh --env=testing --force
```

## Feature tests

```bash
DB_DATABASE=tsms_db_test php artisan test tests/Feature/Ingestion
DB_DATABASE=tsms_db_test php artisan test tests/Unit/Services/TransactionIngestCircuitBreakerTest.php
```

Expected: all pass. Coverage maps to contracts/: batch retirement, ingest outcomes, timestamp rejections, rejection monitor.

## Regression against baseline

```bash
DB_DATABASE=tsms_db_test php artisan test --log-junit tmp/after/junit.xml
```

Compare failing tests with `tmp/baseline/failing.txt` (107 pre-existing failures on main). Acceptable differences: batch-specific tests removed or replaced (listed in tasks.md). Any other newly failing test blocks the slice.

## Lint

```bash
./vendor/bin/pint --test
```

## Manual checks (staging, after an approved deploy only)

- `POST /api/v1/transactions/batch` with a terminal token → 410 JSON.
- `php artisan schedule:list` shows `intake-rejection-monitor`.
- A submission with `transaction_timestamp` `0000-00-00T00:00:00Z` → 422 `INVALID_TRANSACTION_TIMESTAMP`.
