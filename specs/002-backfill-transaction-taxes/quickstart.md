# Quickstart: Validating the Tax Backfill

**Date**: 2026-08-10

End-to-end validation guide. Every step before Step 5 is non-mutating.

## Prerequisites

- Staging on a commit containing the ingestion fix (`4db2a063` ancestor of `HEAD`).
- DB access; `transaction_taxes`, `transactions`, `daily_transaction_summaries` present.
- Off-peak window. The four dashboard endpoints stay stubbed (they are currently JSON-404) — do not re-enable them for this exercise.

## Step 0 — Gating verifications: CLEARED 2026-08-10

Already run; **do not re-run** (V1a is a ~35s full-table scan). Confirmed results in [research.md](research.md):

| Fact | Value |
|------|-------|
| Defect window | 2026-06-13 → 2026-08-10 ~10:00 (59 days) |
| Recoverable | 808,891 (99.97%) |
| Unrecoverable → quarantine | 216 (all 2026-06-13) |
| Boundary | 06-12: 10,825/10,825 captured · 06-13: 0/225 |

Re-run these only if the defect window is disputed or a second affected deployment is discovered.

## Step 0b — AMENDED SEQUENCE (2026-08-10)

V4 found **3,238,180 NULL-keyed orphan rows**; the feature is no longer insert-only. Revised order:

**INSERT-FIRST, pipelined per day** (FR-015a, FR-014a). Originals survive until their replacement is proven in place.

```text
ONCE, up front:
  snapshot aggregates + source label (FR-012/FR-012a — irreversible if skipped)
  → archive ALL orphans (FR-013)
  → verify reconstruction vs post-fix data (FR-008)   ← attribution proof
  → pre-flight assertions (index/FK/nullability), idle-transaction watchdog

THEN PER DAY, 2026-06-13 → 2026-08-09:
  insert reconstructed rows for that day
    → reconcile in situ vs that day's orphans (FR-014, subset/residual)
    → delete that day's orphans  ── EXCEPT 2026-06-13, retained wholesale (FR-015b)
  halt on any mismatch before touching the next day

FINALLY:
  assert connection identity → refresh daily summaries (scoped)
  → validate (incl. tenant isolation)
```

**Do not skip the snapshot.** Once rows are inserted, the pre-backfill rendered aggregate cannot be reconstructed and materiality becomes indefensible.

**2026-06-13 is never deleted.** Only 9 of its 225 transactions are reconstructable; the other 216 have no payload, so their orphan rows are the only surviving record of their tax lines. The whole day is retained (~36 rows over-retained for the 9 reconstructable transactions — accepted).

## Step 1 — Prove reconstruction correctness (the real gate)

```bash
php artisan transactions:verify-tax-reconstruction --sample=500
```

Runs reconstruction against **post-fix** transactions that already have correct tax rows and diffs the result.

**Expected**: zero divergences in `tax_type` and `amount`.

**Any divergence is a hard stop** — it means the backfill would write wrong financial data. Do not proceed to Step 3 until this is clean. (FR-008, research R4.)

## Step 2 — Dry run

```bash
php artisan transactions:backfill-taxes --from=2026-06-13 --to=2026-08-10 --json
```

**Expected**: zero writes; counts of scanned / reconstructable / already-present / quarantined; per-tenant breakdown covering ~87 tenants.

**Sanity checks**: `already-present` should be ~0 inside the window and non-zero if you deliberately widen past 2026-08-10. `quarantined` should be small — a large count means the payload assumption is weaker than V1 suggested.

## Step 3 — Pilot on one tenant

```bash
php artisan transactions:backfill-taxes --from=2026-06-13 --to=2026-08-10 --tenant=<ID> --limit=100 --apply
```

Then verify a corrected transaction reconciles against its own stored cross-check columns (research R3):

```sql
SELECT t.id, t.vat_amount, t.vatable_sales, t.sc_vat_exempt_sales,
       (SELECT SUM(amount) FROM transaction_taxes tt
        WHERE tt.transaction_pk = t.id AND UPPER(tt.tax_type) IN ('VAT','VAT_AMOUNT')) AS reconstructed_vat
FROM transactions t WHERE t.id = <PK>;
```

**Expected**: `reconstructed_vat` matches `t.vat_amount`.

## Step 4 — Idempotency proof (mandatory before full run)

Re-run the exact Step 3 command.

**Expected**: every transaction reports `skipped_existing`; **zero** new rows.

```sql
SELECT transaction_pk, tax_type, COUNT(*) FROM transaction_taxes
WHERE transaction_pk IN (<pilot PKs>)
GROUP BY transaction_pk, tax_type HAVING COUNT(*) > 1;
```

**Expected**: matches the pre-run baseline captured in T075 — **not** necessarily empty, and the NULL-keyed count must equal the *retained residual* (~900 rows from 2026-06-13), **not zero**. Payloads may legitimately repeat a `tax_type` (`taxes` is validated `min:4` with no uniqueness constraint), so a bare "must be zero" check false-positives on correct data (Architect F8). The authoritative check is T076: inserted row ids equal the sum of audit-record reconstructed counts, and the `transaction_pk IS NULL` count equals the **retained residual** (~900 rows from 2026-06-13, FR-015b) — never zero.

## Step 5 — Full backfill

Run without `--tenant`/`--limit`, with `--apply`. Monitor concurrently:

```sql
SHOW PROCESSLIST;   -- must stay calm; no "Waiting for table metadata lock" pile-up
```

**Abort immediately** if lock waits appear on `transactions` — this is the exact failure mode of the 2026-08-10 incident (research R9).

## Step 6 — Confirm coverage (SC-001)

```sql
SELECT DATE(t.created_at) AS day, COUNT(*) AS total,
       SUM(EXISTS (SELECT 1 FROM transaction_taxes tt WHERE tt.transaction_pk = t.id)) AS with_tax
FROM transactions t
WHERE t.created_at >= '2026-06-13' AND t.created_at < '2026-08-10'
GROUP BY DATE(t.created_at) ORDER BY day;
```

**Expected**: `with_tax` ≈ `total` for every day (excluding legitimately non-taxable transactions and quarantined rows).

## Step 7 — Recompute aggregates (FR-006)

Only after Step 6, and only after asserting the aggregating connection is the primary (T077 — **not** a replica-lag wait; both refresh commands already aggregate on the primary, Architect F4):

```bash
php artisan reports:refresh-daily-transaction-summaries --from=2026-06-13 --to=2026-08-10 --tenant=<ID>
```

**The date range is mandatory** — the command defaults to `--days=2` and would otherwise refresh only the last two days, silently failing FR-006. Iterate per tenant and/or per day to keep each transaction small (the refresh wraps ~10⁴ inserts in one transaction by default — the same long-transaction shape as the 2026-08-10 outage).

**Expected**: `other_tax` (and `sc_vat_exempt_sales` for alias variants) change for defect-window dates. **VAT and vatable-sales totals must NOT change** — they were already correct from `transactions` columns. Any movement there means something went wrong; compare against the Step 0b snapshot.

`reporting:refresh transactions_hourly` is **not** required — it derives tax from `transactions` columns and is unaffected (Architect F3).

## Step 8 — Materiality list (FR-009a)

```bash
php artisan transactions:tax-backfill-materiality --run=<RUN_ID> --json
```

**Expected**: reproducible per-tenant/month list. Re-running yields an identical set (SC-006). Notification dispatch is a separate human-approved step.

## Rollback

**No longer a simple insert-only reversal** — the run deletes 3.24M orphans and inserts 3.24M rows. Rollback has two halves, both required:

1. **Undo inserts**: delete rows attributable to the run via its row-level audit records.
2. **Restore orphans**: re-insert from the orphan archive (FR-013). This is why archive-before-delete is mandatory — without it, deletion is irreversible.

Then re-run the aggregate refresh. Note that `daily_transaction_summaries` merges sources with `max()` (Architect F11), so aggregates are **monotonic** — deleting rows alone does not lower a previously-reported figure. Only a refresh after rollback restores prior values.

Take a verified `transaction_taxes` backup before Step 5 regardless of the archive.
