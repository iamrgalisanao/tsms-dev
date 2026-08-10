# Decision Memo — T088a: `other_tax` Row-Level Semantics

**Date**: 2026-08-10 · **Status**: **DECIDED — Option 1, allow-list variant, with explicit VAT-exempt deduction (b)** · **Blocks**: all of `002-backfill-transaction-taxes`

**Decision owner**: architecture (with finance input on the alias sub-question)

## Why this is blocking

`Transaction::otherTaxSum()` counts every non-`VAT` tax row, including `VATABLE_SALES`. Against the PITX formula ([other-tax-semantics.md](other-tax-semantics.md)) that is incoherent — vatable sales is the *base* of GROSS, never a deduction.

It is inert today **only because** defect-window transactions have no linked tax rows, so it falls through to the `sc_vat_exempt_sales` column. Reconstruction activates it.

> **Backfill is unsafe until row-level `other_tax` semantics are aligned with the PITX formula. Current accessor logic treats every non-VAT tax row as other tax, including `VATABLE_SALES`, which would collapse computed net values after reconstruction.**

## Finding that reframes the choice

**`Transaction::otherTaxSum()` is the ONLY live rule anywhere in this codebase for what counts as `other_tax` — and it is wrong.** Two other places contain the correct exclusion set (`VAT`, `VATABLE_SALES`, `SC_VAT_EXEMPT_SALES`), but **both are dead**, for different reasons: one is unreachable code, the other is literally commented out.

| Implementation | Exclusion set | Runs in production? | Verdict |
|---|---|---|---|
| `TSMSTransactionRequest:153` | `VAT`, `VATABLE_SALES`, `SC_VAT_EXEMPT_SALES` | **No.** The logic sits inside a `/* ... */` block comment (`:131-174`), preceded by an explicit policy banner: "Intentionally not enforced during ingestion... TSMS ingestion is passive... must not reject checksum-valid POS payloads by recalculating financial formulas here." Beyond that, the *entire class* never runs: it is type-hinted only on `TransactionController::storeOfficialLegacy()`, which has no route anywhere (the routed handler is `storeOfficial()`, taking a plain `Request`). Laravel never instantiates an unrouted `FormRequest`. | Correct logic, dead twice over |
| `TransactionValidationService::validateAmountReconciliation():688` | `VAT`, `VATABLE_SALES`, `SC_VAT_EXEMPT_SALES` | **No** — see below | Correct logic, but dead |
| `Transaction::otherTaxSum():271` | `VAT` only | **Yes** — via `$appends` on every serialization | **Wrong, and live** |

*(Corrected 2026-08-10 — impact review, 6th pass: an earlier version of this table marked `TSMSTransactionRequest` "Yes — validates every incoming payload," and downstream sections built a "live ingestion gate" risk narrative on that premise. It was never re-verified against the file; a `.php.backup` copy of the same logic, uncommented but not autoloaded, is a plausible source of the confusion. The correction doesn't change the decision below — it removes a risk that was never real.)*

`otherTaxSum()` is the only defect that matters operationally, in the strongest possible sense: it is not merely the rule most worth fixing, it is the **only tax-exclusion rule that executes against real transactions at all.**

`TransactionValidationService::validateAmountReconciliation()` implements the right rule, but it — and its sibling `validateAmounts()` (line 593, which computes `$otherTaxSum` and never uses it) — are both unreachable. `validateTransaction()` (`:518`), the only method either could be reached through from `ProcessTransactionJob`, is a passive no-op that logs and returns `valid => true` without calling either. Both methods sit under an explicit `LEGACY DIAGNOSTIC HELPERS … must not be wired back into ingestion processing` banner (`:528-537`), and neither has a single call site anywhere in `app/` or `tests/`. See consumer-inventory row 3 below.

The `else` branch at `:692-698` (body `:693-697`) that subtracts `sc_vat_exempt_sales` back out of `$otherTaxSum` is inside `validateAmountReconciliation()` — its own fallback for when `$transaction->taxes` isn't loaded — and is therefore dead by the same finding — it never executes, so it cannot be evidence that a previous author deliberately patched around the over-inclusion.

**Consequence for scope**: fixing `otherTaxSum()` is a one-method change to live behavior. Of the two `TransactionValidationService` methods, neither needs a *behavioral* change, but for different reasons. `validateAmounts()` has no exclusion logic of its own — its `:593` line is a bare delegation to `otherTaxSum()` — so it inherits the fix automatically the moment the helper is fixed; the only thing worth touching there is the comment at `:592` ("this includes SC_VAT_EXEMPT_SALES column if present"), which D7's fallback removal makes stale. `validateAmountReconciliation()` already has its own correct inline exclusion logic, independent of `otherTaxSum()`; touching it is purely documentation, to keep its exclusion set textually identical to the fixed allow-list so a future re-wiring inherits the right rule.

## Consumer inventory of the defective helper

| # | Call site | Surface | Post-backfill impact |
|---|---|---|---|
| 1 | `Transaction::getNetAmountAttribute():244` | `$appends['net_amount']` — every JSON serialization | **High** — API-visible on 809,107 transactions |
| 2 | `Transaction::getCalculatedNetSalesAttribute():257` | `$appends['calculated_net_sales']` | **High** — same |
| 3 | `TransactionValidationService::validateAmounts():593` and `validateAmountReconciliation():684-701` | — | **None, both** *(corrected 2026-08-10, impact review)* — `validateTransaction()` (`:518`), the sole caller reachable from `ProcessTransactionJob.php:146`, is a passive no-op that logs and returns `valid => true` without invoking either method. Both are `private`, explicitly labeled "LEGACY DIAGNOSTIC HELPERS … must not be wired back into ingestion processing" (`:528-537`), and have **zero** invocations anywhere in `app/` or `tests/`. My earlier "Medium" rating for `validateAmountReconciliation()` was wrong for the same reason the original "mass validation failure" claim for `validateAmounts()` was wrong — I corrected the first occurrence without checking whether the surrounding method was even reachable. |
| 4 | `JobProcessingService:491` (used at 520) | — | **None** *(corrected pass 4)* — line 520 sits in the `else` of `method_exists($transaction, 'calculateExpectedNetSales')`, and that method exists at `Transaction.php:419`. Dead branch. |

**A fifth net-sales definition exists**: `Transaction::calculateExpectedNetSales()` (`Transaction.php:419`) returns `gross_sales − vat_amount` — live in `JobProcessingService`'s computation-validation path, and different again from the other four. Pre-existing, not introduced here, but named so it is not discovered later.

**Not affected** (verified): `RefreshDailyTransactionSummaries`, `SalesReportDataService`, and `FinanceCalculationService` all use their own SQL/list logic and never call `otherTaxSum()`. `validateAmountReconciliation()`'s inline logic is itself correct but irrelevant to any live behavior — see the consumer-inventory row above: it is unreachable dead code, not merely "unaffected." No frontend code in `resources/js` or `resources/views` reads `net_amount` or `calculated_net_sales`.

**Unknown**: external consumers. `$appends` places both attributes in *every* `Transaction` serialization, so POS providers or webapp clients may consume them. Nothing in-repo tells us. **This is the main residual risk of Option 1 and should be checked before shipping.**

## Worked example (both options must satisfy this)

Observed orphan group, ids 3462340-3462343 @ 2026-06-13 06:12:40:

| `tax_type` | `amount` |
|---|---|
| `VAT` | 6.96 |
| `VATABLE_SALES` | 58.04 |
| `SC_VAT_EXEMPT_SALES` | 0.00 |
| `OTHER_TAX` | 0.00 |

Internally consistent: `58.04 × 12% = 6.9648 ≈ 6.96`. Gross **derived** from the PITX formula (other terms zero): `58.04 + 6.96 = 65.00`. *(Derived, not measured — confirm actual `gross_sales` before quoting externally.)*

| State | `otherTaxSum()` | `net_amount` |
|---|---|---|
| Today (no linked rows) | 0.00 | **65.00** |
| After backfill, helper unfixed | 58.04 | **6.96** ← ≈89% collapse |
| After backfill, helper fixed | 0.00 | **65.00** ← unchanged |

**The fixed helper leaves `net_amount` unchanged for this transaction.** With correct semantics, backfilling is a *no-op* for the accessors — reconstructed rows contribute nothing that wasn't already accounted for.

⚠️ **This property holds only when `sc_vat_exempt_sales = 0`, as it is in this example.** `otherTaxSum()` has a column-fallback branch (`Transaction.php:275-278`): when **no** `SC_VAT_EXEMPT_SALES` *row* exists, it adds the `sc_vat_exempt_sales` *column*. Every window transaction has zero rows today, so the fallback is **live**; reconstruction inserts such a row (even `0.00`) and **disables** it. For any transaction with a non-zero column, `net_amount` shifts by that amount **even with the allow-list fix applied**.

**Resolved by D7**: the fallback mechanism is removed and the VAT-exempt deduction becomes an explicit accessor term sourced from the `sc_vat_exempt_sales` **column** (T088a-6) — exactly neutral for the backfill population, whose current `net_amount` already derives from that column. S7 is reduced to a principle confirmation for finance; the mechanism question is closed.

**Blast radius under D7** *(corrected — an earlier revision of this paragraph described variant (a) and was orphaned when D7 was adopted)*: **out-of-window transactions with linked rows only.** Transactions with zero linked rows and a non-zero `sc_vat_exempt_sales` column — i.e. essentially the whole defect window — are **unaffected**, because D7's explicit term reproduces exactly what the fallback yields today (`gross − 0 − column` = `gross − column`). That neutrality is the point of pinning the source to the column (T088a-6).

---

## Option 1 — Fix helper semantics first (RECOMMENDED)

**Change**: fix the one live defect — `otherTaxSum()` becomes an allow-list counting only `OTHER_TAX` and its aliases. Consumers 1-4 inherit the fix automatically, since they all call `otherTaxSum()`. Nothing behavioral changes in `TransactionValidationService`: `validateAmounts()` delegates to `otherTaxSum()` and inherits the fix for free; `validateAmountReconciliation()` has its own correct-but-dead exclusion logic that is optionally reworded to match the new allow-list textually, purely so a future reader (or a future re-wiring into ingestion) doesn't find a stale rule sitting next to the fixed one. **Out of scope**: `TSMSTransactionRequest`'s broader alias inclusion (it still counts `ZERO_RATED`/`NON-VAT`/etc. as excludable, unlike the new allow-list) is a separate, pre-existing defect — and dead code, not live, so there's nothing to fix urgently — logged to Track B (T088c) rather than touched here.

**Scope**: `app/Models/Transaction.php` (1 method, real behavior change) + regression tests. Optional, no-behavior-change housekeeping: update the stale comment at `TransactionValidationService.php:592`, reword `validateAmountReconciliation()`'s inline exclusion list to match the new allow-list textually, and delete the dead subtract-back branch at `:693-697` (inside the `else` at `:692-698`) since it can no longer be reached.

| For | Against |
|---|---|
| Corrects the live API surface; removes a latent defect independent of this feature | Changes live behaviour *now*, before the backfill, for transactions that already have linked rows |
| Moves `otherTaxSum()` from an unbounded "sum everything but VAT" to the same *kind* of restricted rule the other two implementations already follow (the exact list differs under the adopted sub-decision (b) — see below) | Unknown external `$appends` consumers may depend on current (wrong) values |
| Makes backfill a genuine no-op for accessors (see table above) | Requires deciding the alias sub-question below |
| One definition of `other_tax` in the model layer, permanently | Touches a shared model outside the feature's nominal boundary |

**Blast radius today** (before any backfill): transactions *outside* the defect window that already have linked tax rows. Under D7 they move from `gross − (VATABLE + SC_VAT_EXEMPT + OTHER + aliases)` to `gross − OTHER − sc_vat_exempt_column`, i.e. **increase** (dominated by `VATABLE_SALES` no longer being deducted), plus the T088a-7 alias residual. This is a correction, but it is a visible change to already-published values and must be acknowledged, not glossed.

**Regression coverage required** (final list — see T088a-3a for sequencing; this is the first test coverage `otherTaxSum()` has ever had):
- The 65.00 / 58.04 / 6.96 / 0.00 case → `net_amount` stays 65.00
- Same shape with **non-zero** `OTHER_TAX` (e.g. 10.00) → `net_amount` = 55.00
- A transaction with **no** linked rows and a non-zero `sc_vat_exempt_sales` column → `net_amount` unchanged across the fix (D7 preserves the deduction's *effect*; only the fallback *mechanism* is removed)

No test targets `TransactionValidationService::validateAmounts()` or `::validateAmountReconciliation()` — both are unreachable, `private`, and receive only the textual reword described in Scope above. A test asserting they "agree" would have no way to execute either method without `Reflection`, and there is no runtime behavior to protect.

## Option 2 — Isolate backfilled rows

**Change**: leave live semantics untouched; prevent reconstructed rows from reaching consumers 1-4 until the helper is fixed later.

**Mechanism is the problem.** Three candidates, all with defects:

| Mechanism | Blocker |
|---|---|
| Source-marker column on `transaction_taxes` | Requires a **schema change** to `transaction_taxes` — explicitly out of scope in data-model.md, and adds a column the live ingestion path would also have to populate |
| Distinguish by `created_at` (backfilled rows stamped "now") | Destroys temporal fidelity: reconstructed rows would no longer carry their transaction's time, corrupting the very source-of-truth this feature exists to restore, and breaking the FR-014 per-second multiset reconciliation |
| Join to the backfill audit table to exclude run-inserted ids | No schema change, but puts a join against a 3.24M-row audit table inside a per-transaction accessor — an N+1 on every serialization |

| For | Against |
|---|---|
| Smaller immediate blast radius; no change to already-published values | **Every mechanism has a serious defect** (above) |
| Defers a decision that touches the live API | Leaves two definitions of `other_tax` alive **permanently in practice** — deferred fixes of this kind rarely land |
| — | Creates a semantic trap: any future consumer joining `transaction_taxes` naively gets the wrong answer, with no signal |
| — | Contradicts the feature's own purpose — restoring row-level source of truth while making those rows unsafe to read |

## Sub-decision (applies to Option 1)

The two dead-but-correct implementations named in the table above — `TSMSTransactionRequest:153` and `TransactionValidationService::validateAmountReconciliation()` — both use the same *exclusion* set (`VAT`, `VATABLE_SALES`, `SC_VAT_EXEMPT_SALES`), which still **includes** stray aliases (`VATEXEMPT`, `EXEMPT`, `ZERO_RATED`, `NON-VAT`, `NON_VAT`, `ZERO-RATED`) as "other tax." The PITX formula implies `other_tax` is the `OTHER_TAX` component **only**.

| Choice | Effect |
|---|---|
| **(a) Match existing exclusion set** | Consistent with both dead implementations' pattern; but vat-exempt aliases still count as "other tax" in the fixed `otherTaxSum()`, diverging slightly from PITX |
| **(b) Match PITX exactly** — count only `OTHER_TAX`/`OTHER-TAX` | Strictly correct per the business formula; `otherTaxSum()` then uses a narrower list than either dead implementation, which is fine since neither runs |

**Recommendation: (b), scoped as an allow-list — in `otherTaxSum()` only.** Count only `OTHER_TAX` and its explicit aliases. Rationale: an exclusion list is unbounded — any new tax type a provider invents silently becomes "other tax." An allow-list fails safe.

**Scope is `otherTaxSum()` only — neither dead implementation is edited for risk-avoidance reasons, because there is no risk to avoid.** *(Corrected across two rounds, 2026-08-10: an earlier draft of this recommendation said "align the other two implementations in the same change," naming both without distinguishing them; a subsequent correction narrowed that to "the live `TSMSTransactionRequest` ingestion gate is out of scope, since changing it would be a live API-contract change with its own blast radius" — which assumed `TSMSTransactionRequest` was live. It is not: its exclusion logic is entirely commented out, and the class itself is never instantiated, since its only caller, `storeOfficialLegacy()`, has no route. There is no blast radius from editing dead, commented-out code either way.)* `validateAmountReconciliation()` (also dead) still gets the textual reword described in Option 1's Scope, above, purely so a future re-wiring inherits the right rule — not because leaving it stale carries any present risk. `TSMSTransactionRequest`'s alias over-inclusion is logged as a pre-existing defect for Track B (T088c); it stays out of scope because it's outside this feature's boundary, not because touching it is dangerous.

**D2 is scoped accordingly**: "any unknown future tax type MUST NOT silently become `other_tax`" applies to `otherTaxSum()` (the model layer) — the only place this rule executes.

**Caveat**: whether vat-exempt aliases should count as `other_tax` is a *business* question, not an engineering one. If (b) changes real figures for tenants using those aliases, finance must confirm.

## DECISION (2026-08-10)

**Option 1 — fix helper semantics first, allow-list variant. Confirmed.**

Rationale as recorded by the decision owner: the blast radius is bounded to one live method, and the correct rule already exists in nearby (but dead) code, so there is no design work to invent — the goal is simply to **fix `otherTaxSum()` to the rule that already exists elsewhere in the codebase, before any reconstructed rows become visible**, and to leave the two dead validator methods textually consistent with it for future readers rather than pretending a behavioral convergence is happening where none can occur.

### Binding decision details

| # | Rule |
|---|------|
| D1 | `OTHER_TAX` is **allow-listed**, never inferred by excluding VAT-ish types. |
| D2 | `VATABLE_SALES`, `VAT`, `SC_VAT_EXEMPT_SALES`, VAT-exempt aliases, zero-rated / non-VAT aliases, and **any unknown future tax type** MUST NOT silently become `other_tax`. |
| D3 | Unknown or unsupported tax types MUST be **observable** — logged, quarantined, or raised as a validation warning depending on context — but MUST NOT be counted as `other_tax`. Silent exclusion is as unacceptable as silent inclusion. |
| D4 | `TransactionValidationService::validateAmountReconciliation()` — the one method of the two with its own exclusion logic — MUST be reworded to textually match the `otherTaxSum()` allow-list. `validateAmounts()` needs no equivalent change: it has no exclusion logic of its own, only a bare delegating call to `otherTaxSum()`, so it inherits the fix automatically (its stale comment at `:592` may be updated as housekeeping). Both are documentation-only edits — both methods are unreachable (`validateTransaction()`, their sole path into production, is a passive no-op that calls neither), so there is no behavior to converge and no runtime test to write. This does **not** extend to `TSMSTransactionRequest` (also dead, entirely commented out — see the Sub-decision section above). |
| D5 | The live API-visible behaviour change MUST be acknowledged before deploy, because `$appends` exposes `net_amount` / `calculated_net_sales` externally. |
| D7 | **`net_amount` / `calculated_net_sales` MUST deduct VAT-exempt sales as an EXPLICIT separate term**, not as a side effect inside `otherTaxSum()`. Formula: `gross − otherTaxSum() − scVatExemptSales`. This preserves the PITX principle that VAT-exempt sales are deducted, while avoiding the false +PHP 13.8M movement that (a) would produce by disabling the fallback. The `sc_vat_exempt_sales` column-fallback inside `otherTaxSum()` is **removed** — the deduction moves to the accessor, where it belongs. |
| D8 | **Scope honesty — do not overstate the fix.** Even under D7 these accessors are **not** PITX NET SALES: that formula also deducts promos, senior discount, PWD discount, employee discount and service charge, none of which these accessors handle unless addressed elsewhere. The accurate claim is *"`other_tax` and VAT-exempt semantics are no longer conflated"*, **not** *"net sales is now fully PITX-correct"*. Any communication to finance or tenants MUST use the narrower claim. |
| D6 | **T088a-1 remains a shipping gate**: establish whether any external consumer relies on `net_amount` / `calculated_net_sales` before this ships. Architecturally the decision is settled; empirically the consumer question is not. |

D3 is the substantive addition to the memo's original recommendation. An allow-list that silently drops unrecognised types would trade one invisible failure mode for another — the whole defect class this feature exists to remove began with a value being silently discarded.

## Superseded recommendation (retained for provenance)

**Option 1, sub-decision (b).**

The investigation strengthens the case beyond the original reasoning. Option 2 was attractive on the assumption that Option 1 was large and Option 2 was cheap; both assumptions fail on inspection. Option 1 is four call sites converging on a rule the codebase already implements twice. Option 2 has no mechanism that isn't either out-of-scope, fidelity-destroying, or an N+1.

Decisively: **with the helper fixed, backfilling is a no-op for the accessors.** Option 1 doesn't merely permit the backfill — it removes the interaction entirely. Option 2 preserves the hazard and schedules it for later, while the backfill makes 3.24M rows newly readable through the defective path.

The feature's purpose is restoring row-level source of truth. Publishing rows that are unsafe to read through the model's own accessor would defeat that purpose.

## S7 — quantification specification (input to T084)

The fallback decision is finance's, not engineering's. They must receive population **and** peso exposure without a recommendation embedded in the numbers.

**Required outputs**: affected transaction count · affected tenant count · affected tenant-days · total `sc_vat_exempt_sales` · max single-transaction amount · top 20 tenant-days by amount.

**Scope**: reconstructable window transactions only (`original_payload IS NOT NULL`) — the population whose `net_amount` actually shifts when reconstruction inserts an `SC_VAT_EXEMPT_SALES` row and disables the column-fallback.

```sql
SELECT
  COUNT(*)                                                AS affected_transactions,
  COUNT(DISTINCT tenant_id)                               AS affected_tenants,
  COUNT(DISTINCT CONCAT(tenant_id,'|',DATE(created_at)))  AS affected_tenant_days,
  ROUND(SUM(sc_vat_exempt_sales), 2)                      AS total_sc_vat_exempt_sales,
  ROUND(MAX(sc_vat_exempt_sales), 2)                      AS max_transaction_amount
FROM transactions
WHERE created_at >= '2026-06-13 00:00:00'
  AND created_at <  '2026-08-10 10:00:00'
  AND original_payload IS NOT NULL
  AND COALESCE(sc_vat_exempt_sales, 0) > 0;
```

**Date-basis caveat — must be stated when these figures reach finance.** Every other figure in this feature (V1a window boundary, V4 orphan census, the 808,891 / 216 split) was computed on **`created_at`**. Scoping on `transaction_timestamp` instead selects a materially different population — late-submitted transactions fall on the other side of both boundaries — and this repository has a documented history of UTC/business-date bucketing divergence. Whichever basis is used, name it alongside the numbers; do not mix bases in one statement to finance.

### S7 RESULTS (measured 2026-08-10, `created_at` basis)

| Metric | Value |
|---|---|
| Affected transactions | **82,797** (10.2% of the 808,891 reconstructable) |
| Affected tenants | **69** of ~87 (**79%**) |
| Affected tenant-days | **1,989** |
| Total `sc_vat_exempt_sales` | **PHP 13,818,031.66** |
| Max single transaction | PHP 19,000.00 |
| Mean per affected transaction | PHP 166.89 |
| Mean per tenant-day | PHP 6,947.23 |
| Mean per tenant-month (~2-month window) | **PHP 100,131** |
| Top-20 tenant-days as share of total | **16.4%** — broadly dispersed, not concentrated |

**This is not immaterial.** Mean tenant-month exposure exceeds the PHP 500 materiality threshold by ~200×, across 79% of tenants. If this movement reaches rendered figures, essentially **every affected tenant crosses materiality** and would require notification under FR-009b.

### The result reframes the question

Direction matters. Today, window transactions have zero linked rows, so the fallback is **live**: `net_amount = gross − sc_vat_exempt_sales`. Reconstruction inserts an `SC_VAT_EXEMPT_SALES` row, which **disables** the fallback, so `net_amount` **increases** by that amount — PHP 13.8M in aggregate.

But PITX NET SALES **does** deduct VAT Exempt Sales. So that movement is not a correction; it is a **regression away from the business formula**, and it is an artifact of the fallback mechanism rather than of the backfill itself.

So S7 is not really "keep or remove the fallback". The real question is: **should `net_amount` deduct VAT-exempt sales at all?** Per PITX, yes. The defect is that this deduction is currently smuggled through a helper named `otherTaxSum()`, conflating two distinct quantities.

**Third option, not previously on the table — separate the concerns:**

| | Formula | Aggregate movement |
|---|---|---|
| Today | `gross − sc_vat_exempt` (via fallback) | — |
| (a) Allow-list only, fallback disabled by reconstruction | `gross − OTHER_TAX` | **+PHP 13.8M** — away from PITX |
| **(b) ADOPTED** — allow-list **+ explicit VAT-exempt deduction** | `gross − otherTaxSum() − sc_vat_exempt_column` | **≈ −OTHER_TAX only** — the genuine backfill effect |

Option (b) satisfies D1/D2 (`otherTaxSum()` denotes `OTHER_TAX` alone), preserves the PITX-consistent VAT-exempt deduction as an *explicit* term rather than a fallback side-effect, and isolates the real correction (`OTHER_TAX` now deducted, previously absent because no rows existed) from the spurious PHP 13.8M swing.

**Scope honesty**: even under (b), `net_amount` is still not PITX NET SALES — that formula also deducts promos, senior, PWD, employee discount and service charge. (b) makes it *less wrong*, not correct.

**Interpretation guide (not a recommendation)**:

- `total_sc_vat_exempt_sales` is the aggregate `net_amount` movement if the fallback is **removed**, and equally the aggregate movement if it is **kept** and reconstruction disables it. The two options move the same total in opposite directions.
- `affected_tenant_days` sizes the tenant-communication surface if the change is material.
- Concentration matters more than the total: a few large tenant-days is a targeted conversation; broad dispersion is a policy question.

## Open items before this can be actioned

1. **External `$appends` consumers** — establish whether any POS provider or webapp client reads `net_amount` / `calculated_net_sales`. This is Option 1's main residual risk.
2. **Finance confirmation** on the alias sub-question, if (b) changes figures for tenants using vat-exempt aliases.
3. **Sequencing** — Option 1 changes live behaviour for already-linked transactions *before* the backfill. Confirm this is acceptable as a standalone change, or whether it needs its own review and communication.
4. **Provenance** on the PITX formula (T088b) — this memo's authority rests on it.
