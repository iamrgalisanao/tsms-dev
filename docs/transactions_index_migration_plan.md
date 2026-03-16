# High-Volume POS Transactions Table: Index & Migration Plan

## Design Goals
- **Fast inserts under high concurrency**
- **Clear idempotency rules**
- **Minimal lock contention**
- **Support for void/refund/ops lookups**
- **Reporting indexes off the hot path**

---

## Recommended Final Index Model

**Core Principle:** Use one primary transaction identity rule.

```sql
UNIQUE KEY ux_transactions_transaction_id (transaction_id)
```

- This should be the single idempotency key used by the ingest service.
- Only use composite keys if your business allows duplicate transaction_id across terminals (rare).

---

## Lean Schema Target

```sql
PRIMARY KEY (id),
UNIQUE KEY ux_transactions_transaction_id (transaction_id),
UNIQUE KEY ux_tx_tenant_terminal_receipt_date (tenant_id, terminal_id, receipt_no, transaction_date),
UNIQUE KEY ux_transactions_refund_reference (refund_reference),
KEY idx_transactions_submission_uuid (submission_uuid),
KEY idx_transactions_terminal_id (terminal_id),
KEY idx_transactions_transaction_timestamp (transaction_timestamp),
KEY idx_transactions_original_transaction_id (original_transaction_id)
```

---


## What to Remove

Drop:
- UNIQUE KEY trx_terminal_transaction_unique (terminal_id, transaction_id)
- KEY trx_terminal_submission_lookup (terminal_id, submission_uuid)
- KEY transactions_terminal_receipt_idx (terminal_id, receipt_no)
- KEY idx_tx_tenant_terminal_receipt (tenant_id, terminal_id, receipt_no)
- KEY idx_transactions_refunded (is_refunded)
- KEY idx_transactions_type (transaction_type)

Keep:
- KEY transactions_submission_uuid_index (submission_uuid)
- KEY idx_transactions_original (original_transaction_id)
- KEY idx_transactions_job_status (job_status)
- KEY idx_transactions_terminal_id (terminal_id)
## Completed in staging

These steps are done and verified:

- transactions, transaction_adjustments, and transaction_taxes were truncated to remove disposable verification data
- UNIQUE(transaction_id) was added successfully
- replay/idempotency was verified for:
   - official single submission
   - official batch submission
- the old composite unique key was removed: trx_terminal_transaction_unique
- redundant indexes were removed:
   - transactions_terminal_receipt_idx
   - idx_tx_tenant_terminal_receipt
   - trx_terminal_submission_lookup
   - idx_transactions_refunded
   - idx_transactions_type
- idx_transactions_terminal_id was added and is in use
- transactions_submission_uuid_index is in use and should stay
- idx_transactions_job_status is in use and should stay
- idx_transactions_original is still present and should stay

### Final validated staging index set

This is the schema you should now treat as the target state:

PRIMARY KEY (id)

UNIQUE KEY ux_transactions_transaction_id (transaction_id)
UNIQUE KEY ux_tx_tenant_terminal_receipt_date_unique (tenant_id, terminal_id, receipt_no, transaction_date)
UNIQUE KEY ux_transactions_refund_reference (refund_reference)

KEY transactions_submission_uuid_index (submission_uuid)
KEY idx_transactions_original (original_transaction_id)
KEY idx_transactions_job_status (job_status)
KEY idx_transactions_terminal_id (terminal_id)
## Migration file strategy

Because staging was changed manually, the safest approach is:

- Do not rewrite old historical migrations
- Leave old migrations as-is if they may already have run in other environments.
- Add one new reconciliation migration

Create a new migration that brings any environment to the validated target index set in an idempotent way.

**Recommended migration name:**

php artisan make:migration reconcile_transactions_indexes_for_high_volume_ingest

### What the new migration should do

**In up()**

- Add ux_transactions_transaction_id if missing
- Add idx_transactions_terminal_id if missing
- Drop these if they exist:
  - trx_terminal_transaction_unique
  - trx_terminal_submission_lookup
  - transactions_terminal_receipt_idx
  - idx_tx_tenant_terminal_receipt
  - idx_transactions_refunded
  - idx_transactions_type
- Keep these untouched:
  - transactions_submission_uuid_index
  - idx_transactions_original
  - idx_transactions_job_status
  - ux_tx_tenant_terminal_receipt_date_unique
  - ux_transactions_refund_reference

**In down()**

- Only restore what you are comfortable rolling back. A safe rollback would re-add:
  - trx_terminal_transaction_unique
  - trx_terminal_submission_lookup
  - transactions_terminal_receipt_idx
  - idx_tx_tenant_terminal_receipt
  - idx_transactions_refunded
  - idx_transactions_type
- And drop:
  - ux_transactions_transaction_id
  - idx_transactions_terminal_id
- Do not touch transactions_submission_uuid_index, idx_transactions_original, or idx_transactions_job_status in down().

### Recommended Laravel migration pattern

Because Laravel does not provide a great built-in hasIndex() helper, use information_schema.statistics checks.

Example migration structure:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
   public function up(): void
   {
      $table = 'transactions';

      if (! $this->indexExists($table, 'ux_transactions_transaction_id')) {
         Schema::table($table, function (Blueprint $table) {
            $table->unique('transaction_id', 'ux_transactions_transaction_id');
         });
      }

      if (! $this->indexExists($table, 'idx_transactions_terminal_id')) {
         Schema::table($table, function (Blueprint $table) {
            $table->index('terminal_id', 'idx_transactions_terminal_id');
         });
      }

      $this->dropIndexIfExists($table, 'trx_terminal_transaction_unique');
      $this->dropIndexIfExists($table, 'trx_terminal_submission_lookup');
      $this->dropIndexIfExists($table, 'transactions_terminal_receipt_idx');
      $this->dropIndexIfExists($table, 'idx_tx_tenant_terminal_receipt');
      $this->dropIndexIfExists($table, 'idx_transactions_refunded');
      $this->dropIndexIfExists($table, 'idx_transactions_type');
   }

   public function down(): void
   {
      $table = 'transactions';

      $this->dropIndexIfExists($table, 'ux_transactions_transaction_id');
      $this->dropIndexIfExists($table, 'idx_transactions_terminal_id');

      if (! $this->indexExists($table, 'trx_terminal_transaction_unique')) {
         Schema::table($table, function (Blueprint $table) {
            $table->unique(['terminal_id', 'transaction_id'], 'trx_terminal_transaction_unique');
         });
      }

      if (! $this->indexExists($table, 'trx_terminal_submission_lookup')) {
         Schema::table($table, function (Blueprint $table) {
            $table->index(['terminal_id', 'submission_uuid'], 'trx_terminal_submission_lookup');
         });
      }

      if (! $this->indexExists($table, 'transactions_terminal_receipt_idx')) {
         Schema::table($table, function (Blueprint $table) {
            $table->index(['terminal_id', 'receipt_no'], 'transactions_terminal_receipt_idx');
         });
      }

      if (! $this->indexExists($table, 'idx_tx_tenant_terminal_receipt')) {
         Schema::table($table, function (Blueprint $table) {
            $table->index(['tenant_id', 'terminal_id', 'receipt_no'], 'idx_tx_tenant_terminal_receipt');
         });
      }

      if (! $this->indexExists($table, 'idx_transactions_refunded')) {
         Schema::table($table, function (Blueprint $table) {
            $table->index('is_refunded', 'idx_transactions_refunded');
         });
      }

      if (! $this->indexExists($table, 'idx_transactions_type')) {
         Schema::table($table, function (Blueprint $table) {
            $table->index('transaction_type', 'idx_transactions_type');
         });
      }
   }

   private function indexExists(string $table, string $indexName): bool
   {
      $db = DB::getDatabaseName();

      $result = DB::selectOne(
         "SELECT 1
          FROM information_schema.statistics
          WHERE table_schema = ?
            AND table_name = ?
            AND index_name = ?
          LIMIT 1",
         [$db, $table, $indexName]
      );

      return $result !== null;
   }

   private function dropIndexIfExists(string $table, string $indexName): void
   {
      if ($this->indexExists($table, $indexName)) {
         DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
      }
   }
};
```

**Important staging note**

Because you already changed staging manually, this migration should be idempotent so that:

- on staging: it mostly no-ops safely
- on production: it performs the actual changes in order

That is why the existence checks are important.

---



## Safe Migration Plan (with Execution Guardrails)

### Impact on Existing Staging Records
- Records stay in place; indexes change around them
- Migrations can fail if existing rows violate new uniqueness rules
- Query performance may change after index drops
- No destructive data change should happen unless duplicate cleanup is explicitly performed first



### Phase 0: Pre-check for Duplicates
```sql
SELECT transaction_id, COUNT(*) 
FROM transactions 
GROUP BY transaction_id 
HAVING COUNT(*) > 1;
```
_Clean up any duplicates before proceeding!_


### Phase 0.25: Existing Data Safety Check (Before Any DDL)
### Phase 0.3: Duplicate Remediation

**A. Remediate duplicate transaction_id**

You need to identify, for each duplicated transaction_id, which row is the canonical row and what to do with the duplicate.

**Common options:**
- delete the duplicate row if it is a true accidental replay
- archive the duplicate row to a backup table
- merge data if one row has more complete fields than the other

**Start with this inspection query:**
```sql
SELECT *
FROM transactions
WHERE transaction_id IN (
   SELECT transaction_id
   FROM transactions
   GROUP BY transaction_id
   HAVING COUNT(*) > 1
)
ORDER BY transaction_id, id;
```

Compare duplicates by:
- id
- created_at
- updated_at
- payload_checksum
- submission_uuid
- validation_status
- job_status

A practical default rule is:
- keep the oldest or most complete row
- archive/delete the later duplicate

**Backup before deleting:**
```sql
CREATE TABLE IF NOT EXISTS transactions_duplicates_backup AS
SELECT * FROM transactions WHERE 1=0;

INSERT INTO transactions_duplicates_backup
SELECT *
FROM transactions
WHERE transaction_id IN (
   SELECT transaction_id
   FROM transactions
   GROUP BY transaction_id
   HAVING COUNT(*) > 1
);
```

**Remove duplicates, keep only canonical row (example: keep lowest id):**
```sql
DELETE t1
FROM transactions t1
JOIN (
   SELECT transaction_id, MIN(id) AS min_id
   FROM transactions
   GROUP BY transaction_id
   HAVING COUNT(*) > 1
) t2
ON t1.transaction_id = t2.transaction_id
WHERE t1.id > t2.min_id;
```

**B. Reclassify submission_uuid**

Because duplicates may exist, submission_uuid is behaving like a batch/envelope identifier.

**Target:**
- drop UNIQUE(submission_uuid)
- keep one non-unique index on submission_uuid

```sql
ALTER TABLE transactions DROP INDEX IF EXISTS ux_transactions_submission_uuid;
ALTER TABLE transactions ADD INDEX idx_transactions_submission_uuid (submission_uuid);
```

**C. Re-run duplicate checks after cleanup**

Only when this returns no rows:
```sql
SELECT transaction_id, COUNT(*)
FROM transactions
GROUP BY transaction_id
HAVING COUNT(*) > 1;
```
should you proceed to:
```sql
ALTER TABLE transactions
   ADD UNIQUE KEY ux_transactions_transaction_id (transaction_id);
```

**Recommended execution order now:**
1. Inspect and classify duplicate transaction_id rows
2. Decide cleanup rule
3. Archive/delete/merge duplicates
4. Drop UNIQUE(submission_uuid) and keep one normal index on submission_uuid
5. Re-run duplicate checks
6. Add UNIQUE(transaction_id)
7. Only after that, consider dropping older redundant indexes

**Important:**
Because you may have duplicate transaction_id rows, your current ingest behavior and historical data are still aligned with the old composite identity or with previously weak idempotency enforcement. So this migration is not just an index change anymore — it is a data correction step plus an index change.

**Recommendation:**
- Pause the index rollout
- Do duplicate remediation first
- Treat submission_uuid as non-unique on transactions

**Purpose:** Confirm whether existing staging records will block the new uniqueness rules or behave differently after index changes.

**What will happen to existing records?**
- Existing rows in transactions will not be deleted or rewritten by index changes alone.
- The migration changes index structures, not row values.
- The main risk is migration failure if existing data violates the new unique constraints.
- Dropping indexes will not remove rows, but may change query performance characteristics.

**Required pre-migration checks**

1. **Check for duplicate transaction_id values**
    - This must be clean before adding UNIQUE(transaction_id).
    ```sql
    SELECT transaction_id, COUNT(*)
    FROM transactions
    GROUP BY transaction_id
    HAVING COUNT(*) > 1;
    ```
    - **Action if duplicates exist:**
       - stop the migration
       - classify duplicates as:
          - true duplicates to delete
          - conflicting historical data to archive
          - records requiring merge/manual resolution

2. **Check submission_uuid behavior in existing records**
    - This determines whether submission_uuid should remain unique or become non-unique.
    ```sql
    SELECT submission_uuid, COUNT(*)
    FROM transactions
    WHERE submission_uuid IS NOT NULL
    GROUP BY submission_uuid
    HAVING COUNT(*) > 1;
    ```
    - **Interpretation:**
       - no duplicates → unique may still be valid
       - duplicates exist → submission_uuid should likely be a normal index, not unique

3. **Check receipt uniqueness against the current business rule**
    - This validates the existing receipt/day uniqueness rule.
    ```sql
    SELECT tenant_id, terminal_id, receipt_no, transaction_date, COUNT(*)
    FROM transactions
    WHERE receipt_no IS NOT NULL
    GROUP BY tenant_id, terminal_id, receipt_no, transaction_date
    HAVING COUNT(*) > 1;
    ```
    - **Action if duplicates exist:**
       - review whether these are valid business exceptions
       - confirm whether the receipt/date unique key should stay unchanged

### Phase 0.5: Query-Usage Verification (Before Dropping Indexes)

### Phase 0.5: Query-Usage Verification (Before Dropping Indexes)
- Review slow query log / APM for index usage
- Run EXPLAIN on refund, void, admin, and batch investigation queries
- Only drop the following indexes if truly unused:
   - idx_transactions_refunded
   - idx_transactions_job_status
   - idx_transactions_type
   - transactions_terminal_receipt_idx
   - idx_tx_tenant_terminal_receipt


### Phase 0.75: Staging Impact Verification

Before dropping any indexes, verify that the current staging data still supports the main operational flows after the new unique key is introduced.

**Validate these flows in staging:**
- official single submission replay
- official batch submission replay
- void lookup by transaction_id
- refund lookup path
- submission tracing by submission_uuid
- batch/terminal operational queries

**Success criteria:**
- no duplicate replay creates a second row
- TransactionIngestService continues to return correct idempotent behavior
- no critical admin or ops query regresses unexpectedly

### Phase 1: Add New Idempotency Key
```sql
ALTER TABLE transactions
   ADD UNIQUE KEY ux_transactions_transaction_id (transaction_id);
```

**Phase 1 Execution Note**
- Adding UNIQUE(transaction_id) will scan existing data and may fail if:
   - duplicate transaction_id values exist
   - long-running writes or table-level contention interfere with the DDL on a busy table
- **Recommendation:**
   - run this in a controlled maintenance/deployment window
   - if the table is large, consider online schema change tooling
   - do not drop trx_terminal_transaction_unique until Phase 1 succeeds and replay behavior is verified in staging

### Phase 2: Drop Obvious Duplicate Indexes
```sql
ALTER TABLE transactions DROP INDEX transactions_submission_uuid_index;
```

### Phase 3: Inspect and Adjust submission_uuid Indexes
- Inspect current state:
   - If both unique and non-unique submission_uuid indexes exist, keep only one (based on business logic: unique if one submission = one transaction, non-unique if batch).
   - Do not blindly add a new index—adjust or drop/replace as needed.
   - Example:
      ```sql
      -- If keeping unique:
      -- ALTER TABLE transactions ADD UNIQUE KEY ux_transactions_submission_uuid (submission_uuid);
      -- If keeping non-unique:
      -- ALTER TABLE transactions ADD KEY idx_transactions_submission_uuid (submission_uuid);
      -- ALTER TABLE transactions DROP INDEX ux_transactions_submission_uuid;
      ```


### Phase 4: Drop Old Composite/Receipt/Status Indexes One at a Time (with Monitoring)
- **Phase 4 Execution Note**
   - Drop candidate indexes one per migration / one per maintenance step, not all at once.
   - After each index drop, monitor:
      - insert latency
      - deadlock count
      - slow queries
      - official replay behavior
      - void/refund query performance
   - This keeps rollback simple and makes regressions easy to isolate.

After query-usage verification (Phase 0.5), drop each index individually and monitor for impact:
   ```sql
   ALTER TABLE transactions DROP INDEX trx_terminal_transaction_unique;
   ALTER TABLE transactions DROP INDEX trx_terminal_submission_lookup;
   ALTER TABLE transactions DROP INDEX transactions_terminal_receipt_idx;
   ALTER TABLE transactions DROP INDEX idx_tx_tenant_terminal_receipt;
   ALTER TABLE transactions DROP INDEX idx_transactions_refunded;
   ALTER TABLE transactions DROP INDEX idx_transactions_job_status;
   ALTER TABLE transactions DROP INDEX idx_transactions_type;
   ```

---

## Recommended Rollout Order

1. Duplicate check on transaction_id
2. Add UNIQUE(transaction_id)
3. Verify service and replay behavior in staging
4. Drop the obvious duplicate transactions_submission_uuid_index
5. Review actual submission_uuid semantics, then keep either unique or non-unique, not both
6. Drop old composite/receipt/status indexes one at a time with monitoring after each change

_This order ensures safe, observable migration and minimizes risk of ingest disruption._

---

## Notes
- **Do not overload the ingest table with reporting indexes.**
- **Replicate or ETL into a reporting table for heavy reporting needs.**
- **Keep the ingest table lean for best performance.**

---

## Bottom Line
- One clear idempotency unique key
- One receipt uniqueness rule (if required)
- A few operational lookup indexes
- No duplicate or speculative indexes

This will materially reduce insert latency, deadlock probability, lock contention, and index maintenance overhead.
