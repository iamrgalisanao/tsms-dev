# Decision Memo — T088a: `other_tax` Row-Level Semantics

**Date**: 2026-08-10 · **Status**: **DECIDED — Option 1, allow-list variant** · **Blocks**: all of `002-backfill-transaction-taxes`

**Decision owner**: architecture (with finance input on the alias sub-question)

## Why this is blocking

`Transaction::otherTaxSum()` counts every non-`VAT` tax row, including `VATABLE_SALES`. Against the PITX formula ([other-tax-semantics.md](other-tax-semantics.md)) that is incoherent — vatable sales is the *base* of GROSS, never a deduction.

It is inert today **only because** defect-window transactions have no linked tax rows, so it falls through to the `sc_vat_exempt_sales` column. Reconstruction activates it.

> **Backfill is unsafe until row-level `other_tax` semantics are aligned with the PITX formula. Current accessor logic treats every non-VAT tax row as other tax, including `VATABLE_SALES`, which would collapse computed net values after reconstruction.**

## Finding that reframes the choice

**Correct semantics already exist in this codebase — twice.** This is not a new rule to invent; it is an inconsistency to resolve.

| Implementation | Exclusion set | Verdict |
|---|---|---|
| `TSMSTransactionRequest:152` (ingestion gate) | `VAT`, `VATABLE_SALES`, `SC_VAT_EXEMPT_SALES` | Near-correct |
| `TransactionValidationService::validateAmountReconciliation():688` | `VAT`, `VATABLE_SALES`, `SC_VAT_EXEMPT_SALES` | Near-correct |
| `Transaction::otherTaxSum():271` | `VAT` only | **Wrong** |

Both correct implementations use the *same* exclusion set. `TransactionValidationService` therefore contains **two contradictory definitions in one class**: `validateAmounts()` (line 593) uses the wrong helper, while `validateAmountReconciliation()` (line 688) implements the right rule inline.

*(Corrected 2026-08-10)* Lines 693-696 call `otherTaxSum()` then subtract back `sc_vat_exempt_sales` — but they sit in an `else` branch guarded by `method_exists($transaction, 'taxes')`, always true on the model. It is **dead code**, so the inference that a previous author deliberately patched around the over-inclusion is unsupported and is withdrawn.

**Correction to my earlier framing**: I previously characterised Option 1 as "correct but widens scope onto the live API surface." Having traced it, that overstated the cost. The blast radius is **four call sites**, and the change aligns the helper with two existing implementations rather than introducing a new rule.

## Consumer inventory of the defective helper

| # | Call site | Surface | Post-backfill impact |
|---|---|---|---|
| 1 | `Transaction::getNetAmountAttribute():244` | `$appends['net_amount']` — every JSON serialization | **High** — API-visible on 809,107 transactions |
| 2 | `Transaction::getCalculatedNetSalesAttribute():257` | `$appends['calculated_net_sales']` | **High** — same |
| 3 | `TransactionValidationService::validateAmounts():593` | — | **None** *(corrected)* — assigns `$otherTaxSum` and **never uses it**; verified as the sole occurrence in the method. Real exposure is `validateAmountReconciliation():684-701`, whose tax loop is empty today and becomes non-empty post-reconstruction: **Medium** |
| 4 | `JobProcessingService:491` (used at 520) | Net-sales computation during processing | **Medium** — window transactions are already processed; risk is on reprocessing |

**Not affected** (verified): `validateAmountReconciliation()` uses correct inline logic; `RefreshDailyTransactionSummaries`, `SalesReportDataService`, and `FinanceCalculationService` all use their own SQL/list logic and never call the helper. No frontend code in `resources/js` or `resources/views` reads `net_amount` or `calculated_net_sales`.

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

D1-D6 do not decide the fallback's fate. It is escalated to finance as **S7**, to be presented with quantified exposure (count, peso total, aggregate `net_amount` delta), because PITX NET SALES *does* deduct VAT Exempt Sales — so removing the fallback moves away from the formula, while keeping it means `otherTaxSum()` remains a mixed net-sales helper rather than denoting `OTHER_TAX`.

**Revised blast radius**: not only out-of-window transactions with linked rows, but also any transaction with zero linked rows and a non-zero `sc_vat_exempt_sales` column — potentially much of the defect window itself.

---

## Option 1 — Fix helper semantics first (RECOMMENDED)

**Change**: `otherTaxSum()` counts only `OTHER_TAX` and its aliases. Consumers 1-4 inherit the fix. `validateAmounts()` and `validateAmountReconciliation()` converge on one definition.

**Scope**: `app/Models/Transaction.php` (1 method) + regression tests. Optionally collapse `validateAmountReconciliation()`'s inline logic onto the shared helper to remove the duplicate rule; optionally remove the line 693-696 subtract-back workaround, which becomes unnecessary.

| For | Against |
|---|---|
| Corrects the live API surface; removes a latent defect independent of this feature | Changes live behaviour *now*, before the backfill, for transactions that already have linked rows |
| Aligns with two existing correct implementations — consistency, not novelty | Unknown external `$appends` consumers may depend on current (wrong) values |
| Makes backfill a genuine no-op for accessors (see table above) | Requires deciding the alias sub-question below |
| One definition of `other_tax` in the model layer, permanently | Touches a shared model outside the feature's nominal boundary |

**Blast radius today** (before any backfill): transactions *outside* the defect window that already have linked tax rows. Their `net_amount` would change from `gross − (VATABLE + SC_VAT_EXEMPT + OTHER)` to `gross − OTHER`, i.e. **increase**. This is a correction, but it is a visible change to already-published values and must be acknowledged, not glossed.

**Regression coverage required**:
- The 65.00 / 58.04 / 6.96 / 0.00 case → `net_amount` stays 65.00
- Same shape with **non-zero** `OTHER_TAX` (e.g. 10.00) → `net_amount` = 55.00
- Non-zero `SC_VAT_EXEMPT_SALES` → confirm the intended treatment per the sub-question
- A transaction with **no** linked rows and a non-zero `sc_vat_exempt_sales` column → confirm the column-fallback branch still behaves as intended
- `validateAmounts()` and `validateAmountReconciliation()` agree on the same fixture

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

Both existing correct implementations exclude `VAT`, `VATABLE_SALES`, `SC_VAT_EXEMPT_SALES` — so they still **include** stray aliases (`VATEXEMPT`, `EXEMPT`, `ZERO_RATED`, `NON-VAT`, `NON_VAT`, `ZERO-RATED`). The PITX formula implies `other_tax` is the `OTHER_TAX` component **only**.

| Choice | Effect |
|---|---|
| **(a) Match existing exclusion set** | Consistent with the ingestion gate and reconciliation validator; lowest risk; but vat-exempt aliases still count as "other tax", diverging slightly from PITX |
| **(b) Match PITX exactly** — count only `OTHER_TAX`/`OTHER-TAX` | Strictly correct per the business formula; but diverges from two existing implementations, which would then also need aligning |

**Recommendation: (b), scoped as an allow-list.** Count only `OTHER_TAX` and its explicit aliases, and align the other two implementations in the same change. Rationale: an exclusion list is unbounded — any new tax type a provider invents silently becomes "other tax". An allow-list fails safe. The alias set itself should be confirmed against Track B, which owns alias normalization.

**Caveat**: whether vat-exempt aliases should count as `other_tax` is a *business* question, not an engineering one. If (b) changes real figures for tenants using those aliases, finance must confirm.

## DECISION (2026-08-10)

**Option 1 — fix helper semantics first, allow-list variant. Confirmed.**

Rationale as recorded by the decision owner: the blast radius is bounded and the correct rule already exists in nearby code; the goal is to **collapse the contradictory definitions into one shared semantic rule before any reconstructed rows become visible**.

### Binding decision details

| # | Rule |
|---|------|
| D1 | `OTHER_TAX` is **allow-listed**, never inferred by excluding VAT-ish types. |
| D2 | `VATABLE_SALES`, `VAT`, `SC_VAT_EXEMPT_SALES`, VAT-exempt aliases, zero-rated / non-VAT aliases, and **any unknown future tax type** MUST NOT silently become `other_tax`. |
| D3 | Unknown or unsupported tax types MUST be **observable** — logged, quarantined, or raised as a validation warning depending on context — but MUST NOT be counted as `other_tax`. Silent exclusion is as unacceptable as silent inclusion. |
| D4 | `validateAmounts()` and `validateAmountReconciliation()` MUST share the same helper and the same allow-list. No second definition may survive. |
| D5 | The live API-visible behaviour change MUST be acknowledged before deploy, because `$appends` exposes `net_amount` / `calculated_net_sales` externally. |
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

**Interpretation guide (not a recommendation)**:

- `total_sc_vat_exempt_sales` is the aggregate `net_amount` movement if the fallback is **removed**, and equally the aggregate movement if it is **kept** and reconstruction disables it. The two options move the same total in opposite directions.
- `affected_tenant_days` sizes the tenant-communication surface if the change is material.
- Concentration matters more than the total: a few large tenant-days is a targeted conversation; broad dispersion is a policy question.

## Open items before this can be actioned

1. **External `$appends` consumers** — establish whether any POS provider or webapp client reads `net_amount` / `calculated_net_sales`. This is Option 1's main residual risk.
2. **Finance confirmation** on the alias sub-question, if (b) changes figures for tenants using vat-exempt aliases.
3. **Sequencing** — Option 1 changes live behaviour for already-linked transactions *before* the backfill. Confirm this is acceptable as a standalone change, or whether it needs its own review and communication.
4. **Provenance** on the PITX formula (T088b) — this memo's authority rests on it.
