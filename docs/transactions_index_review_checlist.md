# Transactions Table Index Review Checklist

A practical checklist for reviewing and optimizing indexes on the `transactions` table for **high-concurrency POS ingestion** and **idempotent transaction processing**.

---

# Goal

Ensure the `transactions` table indexes support:

* idempotent ingest
* common operational queries
* reporting filters

Avoid indexes that:

* overlap heavily
* no longer match the service logic
* increase insert overhead unnecessarily

---

# 1. Confirm the Single Source of Idempotency

The ingest service treats the following as the **unique transaction identity**:

```
transaction_id
```

The database must enforce the same rule.

### Verify

```sql
SHOW INDEX FROM transactions;
```

Look for:

```
UNIQUE(transaction_id)
```

### Action

If missing:

```sql
ALTER TABLE transactions
ADD UNIQUE KEY ux_transactions_transaction_id (transaction_id);
```

### Review Question

Does any code still rely on:

```
UNIQUE(terminal_id, transaction_id)
```

If not, that composite index may be removed after validation.

---

# 2. Review All Unique Indexes

For every unique index on `transactions`, ask:

### A. Does it enforce a real business rule?

Examples:

* `transaction_id` → core idempotency
* `submission_uuid` → maybe (if submissions are unique)
* `(tenant_id, terminal_id, receipt_no, transaction_date)` → depends on receipt uniqueness rule

### B. Is the rule still used by the application?

If the service or controller no longer depends on it, the index may only increase insert cost.

### C. Does it overlap another unique index?

Overlapping unique indexes increase lock contention during inserts.

---

# 3. Review Hot-Path Lookup Indexes

These indexes usually provide the most operational value.

---

## A. transaction_id

Keep:

```sql
UNIQUE KEY ux_transactions_transaction_id (transaction_id)
```

Used for:

* idempotency
* transaction replay detection
* operational lookups
* void/refund checks

---

## B. submission_uuid

Keep if submissions are frequently traced or audited.

```sql
CREATE INDEX idx_transactions_submission_uuid
ON transactions (submission_uuid);
```

Used for:

* submission tracing
* audit workflows
* operational investigation

Remove if submission lookups occur in another table.

---

## C. terminal_id

Keep if terminal-scoped queries are common.

```sql
CREATE INDEX idx_transactions_terminal_id
ON transactions (terminal_id);
```

Used for:

* terminal filtering
* POS diagnostics
* ownership checks

---

## D. transaction_timestamp

Consider keeping for reporting and time-range queries.

```sql
CREATE INDEX idx_transactions_transaction_timestamp
ON transactions (transaction_timestamp);
```

Used for:

* reporting queries
* refund day validation
* recent transaction retrieval

---

# 4. Review Composite Indexes

Composite indexes are often the source of insert slowdown.

Evaluate each composite index.

### Ask:

1. Does a real query start with the leftmost column?
2. Is the index redundant because of a wider index?
3. Is it tied to a business rule that still exists?

Example:

```
(tenant_id, terminal_id, receipt_no, transaction_date)
```

May make this redundant:

```
(tenant_id, terminal_id, receipt_no)
```

---

# 5. Check for Duplicate or Redundant Indexes

Run:

```sql
SHOW INDEX FROM transactions;
```

Look for patterns like:

* same column indexed twice
* same left-prefix repeated
* unique index duplicated as non-unique

### Example

Bad:

```
UNIQUE(submission_uuid)
INDEX(submission_uuid)
```

Keep only the unique version.

---

# 6. Validate Query Usage Before Dropping Indexes

Before removing an index, check real query patterns.

Sources:

* application query logs
* slow query logs
* APM traces
* production dashboards
* admin/reporting tools

### Use

```sql
EXPLAIN SELECT ...
```

Do not drop an index unless:

* it is redundant
* no important query uses it

---

# 7. Understand Insert Cost

Every insert must update:

* primary key
* all unique indexes
* all secondary indexes

More indexes cause:

* additional B-tree updates
* more locking
* greater deadlock risk
* increased buffer pool pressure

For POS ingest systems, **fewer indexes is usually better**.

---

# 8. Recommended Minimal Index Set

A lean starting configuration:

```sql
PRIMARY KEY (id)

UNIQUE KEY ux_transactions_transaction_id (transaction_id)

KEY idx_transactions_submission_uuid (submission_uuid)

KEY idx_transactions_terminal_id (terminal_id)

KEY idx_transactions_transaction_timestamp (transaction_timestamp)
```

Add more indexes only if justified by real queries.

---

# 9. Suggested Review Procedure

### Step 1

Export the current structure.

```sql
SHOW CREATE TABLE transactions;
SHOW INDEX FROM transactions;
```

---

### Step 2

Classify each index as:

* idempotency critical
* operational lookup
* reporting
* legacy/unknown

---

### Step 3

Mark indexes:

* keep
* verify usage
* candidate for removal

---

### Step 4

Test candidate removals in staging using:

* concurrent batch ingest
* official submission replay
* void/refund operations
* reporting queries

---

### Step 5

Drop indexes gradually.

Never remove multiple indexes in the same deployment.

---

# 10. Safe Migration Strategy

Always apply index changes separately from code refactors.

Recommended order:

1. merge controller/service refactor
2. observe ingest stability
3. add/adjust the core unique index
4. remove redundant indexes one at a time

Each migration must include a rollback:

```php
public function down()
{
    // recreate index if needed
}
```

---

# 11. First Things to Verify

Check these immediately:

1. `UNIQUE(transaction_id)` exists
2. no legacy `UNIQUE(terminal_id, transaction_id)`
3. `submission_uuid` is not indexed twice
4. receipt-based composite indexes still match real rules
5. `terminal_id` has a lookup index
6. `transaction_timestamp` supports reporting queries

---

# 12. Practical Decision Guide

### Keep

```
PRIMARY KEY(id)
UNIQUE(transaction_id)
```

---

### Usually Keep

```
INDEX(submission_uuid)
INDEX(terminal_id)
INDEX(transaction_timestamp)
```

---

### Review Carefully

* receipt-based composite indexes
* tenant/terminal/receipt combinations
* legacy transaction identity indexes
* duplicate indexes on the same column

---

# Appendix: Quick Inspection Command

```sql
SHOW INDEX FROM transactions;
```

Paste the output into the review checklist and mark each index as:

```
KEEP
VERIFY
DROP CANDIDATE
```

---

# Outcome

Following this checklist ensures:

* fast high-volume ingest
* consistent idempotency
* reduced deadlock risk
* maintainable index strategy
* scalable POS transaction processing
