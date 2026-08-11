# Slice 11 Architecture Brief — Stratified Oracle Sampling (T101)

**Date**: 2026-08-12 · **Status**: Approved to implement against this brief

## Why this brief exists

`transactions:verify-tax-reconstruction` (Slice 5) is this feature's primary safety gate — it must report zero divergences before `--apply` is ever trusted. Its sampling today is a single random-offset contiguous-id window across the *entire* candidate pool (deliberately not `ORDER BY RAND()`, to avoid a filesort — see Slice 5's own review). That's fine for avoiding a slow query, but it gives no guarantee of *breadth*: a run could, by chance, sample almost entirely from one or two large tenants on one or two days and never touch the rest of the ~87-tenant, ~59-day population. T101 exists to close that gap before this feature moves toward orphan cleanup (T066-T072), which depends on this oracle's credibility.

## Scope contract

```text
Allowed:
- Replace the sampling method in VerifyTaxReconstruction with one
  stratified by day, then by tenant within each day.
- Extend --json (and human) output with coverage metadata: how many
  distinct (day, tenant) strata exist in the candidate pool, how many
  were actually represented in the sample, and a per-day / per-tenant
  sampled-vs-pool-count breakdown.
- Tests proving the sample spans multiple distinct tenants and multiple
  distinct days when the pool contains multiple of each — not just
  passing by chance.

Not allowed:
- Any change to the multiset comparison logic (diff()), the exit-code
  rules, the --from default, or the zero-divergence/empty-pool-fails
  requirements — all unchanged from Slice 5.
- Any write anywhere — this command stays 100% read-only.
- No CLI wiring into BackfillTransactionTaxes.php, no changes to
  TaxBackfillRunner/TaxBackfillPreflightChecker — unrelated command.
- No archive/reconcile/delete, aggregate refresh, materiality.
```

## The allocation algorithm

**Goal is breadth, not population-proportional representation.** A population-proportional allocation (bigger tenant-day strata get more samples) would still let the sample concentrate on the largest few strata when `--sample` is small relative to the number of distinct strata — exactly the failure mode this slice exists to close. Use **round-robin water-filling** instead: every stratum gets one unit before any stratum gets a second, so breadth is maximized first, and additional depth is only added once every stratum already has equal representation.

```text
function allocate(int $total, array $capacities): array   // capacities keyed by stratum id, in a stable/deterministic order (sorted)
    allocated = every key -> 0
    remaining = total
    while remaining > 0:
        madeProgress = false
        for each (key, cap) in capacities, in stable order:
            if remaining == 0: break
            if allocated[key] < cap:
                allocated[key] += 1
                remaining -= 1
                madeProgress = true
        if not madeProgress: break   // every stratum already at full capacity (sample size >= pool size)
    return allocated
```

Apply this **twice**, nested:
1. **Level 1 — across days**: `capacities` = each distinct day's total candidate count in the window. Allocate the overall `--sample` budget across days.
2. **Level 2 — within each day**: for that day's allocated quota, `capacities` = each distinct tenant's candidate count *on that day*. Allocate that day's quota across its tenants.

Implement `allocate()` as one small, pure, independently unit-tested method — used at both levels, not duplicated.

**Row selection within a stratum**: once a (day, tenant) stratum's quota is known, select that many rows via `inRandomOrder()->limit($quota)` scoped to that stratum specifically (`WHERE tenant_id = ? AND DATE(created_at) = ?`). This is safe here — unlike Slice 5's original full-pool sampling, a single stratum is a small, already-filtered slice of the table (a handful to a few hundred rows for one tenant on one day), so the `ORDER BY RAND()` filesort concern that drove Slice 5's original random-offset design doesn't apply at this granularity. Document this reasoning explicitly so a future reader doesn't think it's an inconsistency with Slice 5's stated performance concern.

## Output additions

Extend the existing result structure (`buildResult()`-equivalent in this command) with a `coverage` section:
- `total_strata`: distinct (day, tenant) pairs in the full candidate pool.
- `sampled_strata`: distinct (day, tenant) pairs actually represented in the drawn sample.
- `per_day`: list of `{day, pool_count, sampled_count}` for every distinct day in the pool (not just sampled ones — an operator should see which days got zero coverage too, if any, when `--sample` is smaller than `total_strata`).
- `per_tenant`: same shape, keyed by tenant instead of day.

This is display/reporting only — it does not change the pass/fail verdict, which remains exactly "zero divergences across the drawn sample, non-empty pool required."

## Test plan

- **Unit tests for `allocate()`** in isolation: `total >= sum(capacities)` gives everyone their full capacity; `total < number of buckets` spreads one unit to as many buckets as fit before any bucket gets a second (assert exact allocation for a few concrete capacity sets, not just "sums to total"); a single-bucket case; a zero-total case.
- **Multi-tenant, multi-day breadth**: construct a fixture spanning at least 3 distinct days and at least 3 distinct tenants per day, request a `--sample` large enough to cover every stratum at least once, and assert every distinct tenant AND every distinct day actually appears in the checked set (not just that the counts are plausible) — read this back from the actual sampled transaction ids' tenant/day, not just from `coverage`'s self-reported numbers (a bug in the allocator could report numbers that don't match what was actually queried).
- **`--sample` smaller than total strata**: confirm the sample still spans as many *distinct* days/tenants as the round-robin algorithm allows (i.e., `sampled_strata` should equal `min(--sample, total_strata)`, not cluster into fewer strata than the sample size permits).
- **Regression**: every existing Slice 5 test (multiset comparison correctness, empty-pool failure, the T096 duplicate-type proof, `--from` default reasoning) still passes unchanged — this slice only replaces *which* transactions get selected for checking, not what happens once they're selected.
- **`coverage` output correctness**: `per_day`/`per_tenant` pool counts match independently-computed totals from the fixture; `total_strata`/`sampled_strata` are internally consistent with the per_day/per_tenant lists.

## What's explicitly deferred

- Any change to how divergences are reported (`diff()`, per-transaction output) — unchanged.
- CLI/operator-facing gate wiring (e.g., a script that checks `coverage` before authorizing `--apply`) — this slice only makes the data available, per Slice 5's own forward note on T026 about `checked_count`/`candidate_pool_size` needing to be inspected by whoever builds that gate; `coverage` extends that same pattern, consuming it is still someone else's job.
- Orphan archive/reconcile/delete, aggregate refresh, materiality.
