# `other_tax` Semantics — Business Authority and Code Divergence

**Date**: 2026-08-10
**Status**: BLOCKING for `002-backfill-transaction-taxes` · **Resolution DECIDED** — see [decision memo](decision-t088a-other-tax-semantics.md)

## Provenance (read this first)

The business rules below were transcribed from a comparison worksheet ("NST RECEIPT COMPUTATION" vs "PITX Computation") supplied by the user on 2026-08-10 during architecture review.

**Provenance is incomplete and must be closed before this document is treated as authoritative:**

| Unknown | Needed |
|---------|--------|
| Owner / author | Who produced the worksheet |
| Date / version | When, and whether superseded |
| Status | Approved business rule, or working draft |
| Canonical location | Where the source-of-record lives |

**Why this matters**: the only other written definitions live in `docs/archive/`, which `.gitignore:87` excludes — they are untracked local files, never committed, and they contradict themselves (below). The plan must not rest on an untracked image as its sole business-rule authority. Either promote the worksheet to a tracked source document, or have this summary confirmed and signed by its owner.

## The PITX formulas as transcribed

```
GROSS     = Vatable Sales + 12% VAT + (VAT exempt − Senior Disc.)
            + Promos + Senior Disc. + PWD Disc. + Service charge + Others

NET SALES = Gross − Promos − Senior disc. − PWD Disc. − employee discount
            − Other Tax − Service charge − VAT Exempt Sales

VAT       = Vatable Sales × 12%
```

Noted in the same worksheet: PITX has **no** formula for discount, service charge, vatable, vat-exempt, or pax counts — those are taken from the z-reading as given. Only GROSS, NET SALES, and VAT are computed.

### Two consequences that settle the open question

1. **`Other Tax` and `VAT Exempt Sales` are deducted separately** in NET SALES. Therefore `other_tax` does **not** include VAT-exempt.
2. **`Vatable Sales` is never deducted** — it is the base term in the GROSS construction. Therefore `other_tax` must **not** include `VATABLE_SALES`.

`Others` in GROSS and `Other Tax` in NET SALES are the same quantity: added into gross, removed again for net.

**Conclusion**: `other_tax` means the `OTHER_TAX` component (and its aliases) only.

### This corrects the archived guidelines

`docs/archive/_md_shadow/TSMS_POS_Transaction_Payload_Guidelines_v2.md` contradicts itself:

- Prose: *"other_tax: Sum of tax amounts where `tax_type ≠ 'VAT'`"* — **wrong**, would sweep in `VATABLE_SALES` and vat-exempt.
- Worked example: *"Other Tax: 10.00 (OTHER_TAX only, VAT excluded)"* — **correct**, matches the PITX formula.

## Four-way code divergence

| # | Path | Rule | Counts `OTHER_TAX`? | Counts `VATABLE_SALES`? | vs PITX |
|---|------|------|:---:|:---:|---------|
| 1 | `TSMSTransactionRequest:153` | excludes `VAT`, `VATABLE_SALES`, `SC_VAT_EXEMPT_SALES` | yes | no | **Near-correct logic, but DEAD** *(corrected 2026-08-10, impact review 6th pass — an earlier version of this row called it "ingestion gate" and treated it as live)*: the exclusion logic sits inside a `/* ... */` block comment (`:131-174`) under an explicit "not enforced during ingestion" policy, and the containing class is never instantiated in production — its only caller, `TransactionController::storeOfficialLegacy()`, has no route. Slightly over-inclusive on alias types (`ZERO_RATED`, `NON-VAT`, …) if it ever ran |
| 2 | `RefreshDailyTransactionSummaries.php:120` (SQL) | excludes 13 VAT/vat-exempt aliases | yes | no | **Closest to correct** |
| 3 | `FinanceCalculationService::NON_OTHER_TAX_TYPES` | excludes those 13 **+ `OTHER_TAX`, `OTHER-TAX`** | **no** | no | **Wrong** — excludes the one component `other_tax` denotes; payload-derived `other_tax` is therefore ~always `0.00` |
| 4 | `Transaction::otherTaxSum()` | `where('tax_type','!=','VAT')->sum('amount')` | yes | **yes** | **Badly wrong** — subtracts the vatable base from gross |

A fifth, separate defect: `RefreshDailyTransactionSummaries.php:68` computes `transaction_other_tax = COALESCE(SUM(t.tax_exempt),0)`, but `tax_exempt` is a **boolean** (`Transaction.php:317`). That term is a *count of tax-exempt transactions* wearing a currency label, and it participates in the `max()` merge at line 168.

## BLOCKER — why the backfill cannot proceed

> **Backfill is unsafe until row-level `other_tax` semantics are aligned with the PITX formula. Current accessor logic treats every non-VAT tax row as other tax, including `VATABLE_SALES`, which would collapse computed net values after reconstruction.**

`otherTaxSum()` is inert today **only because** defect-window transactions have no linked tax rows — it falls through to the `sc_vat_exempt_sales` column. Reconstruction activates it.

### Worked arithmetic

Using the observed orphan row group (ids 3462340-3462343, `created_at` 2026-06-13 06:12:40):

| `tax_type` | `amount` |
|---|---|
| `VAT` | 6.96 |
| `VATABLE_SALES` | 58.04 |
| `SC_VAT_EXEMPT_SALES` | 0.00 |
| `OTHER_TAX` | 0.00 |

Internally consistent with the PITX formula: `58.04 × 12% = 6.9648 ≈ 6.96`. Gross derived per the formula (all other terms zero): `58.04 + 6.96 = 65.00`.

*(Gross is **derived** from the formula here, not read from the row — confirm against the actual `gross_sales` value before quoting this externally.)*

| State | `otherTaxSum()` | `net_amount = gross − otherTaxSum` |
|---|---|---|
| **Today** (no linked rows) | `0.00` (column fallback) | `65.00 − 0.00 = ` **65.00** |
| **After backfill** | `58.04 + 0.00 + 0.00 = 58.04` | `65.00 − 58.04 = ` **6.96** |

**≈ 89% collapse**, on `$appends` attributes (`net_amount`, `calculated_net_sales`) serialized into **every API response** for the affected transactions — 809,107 of them. *(Corrected 2026-08-10, further corrected on impact-review re-check)* `TransactionValidationService::validateAmounts()` assigns `$otherTaxSum` at line 593 but **never uses it**. `::validateAmountReconciliation()` (lines 684-701) does use its own inline logic correctly — but `validateTransaction()`, the only method either could be reached through in production, is a passive no-op that calls neither. **Both are entirely unreachable dead code.** (A separate, live `JobProcessingService::validateAmounts()` does call `otherTaxSum()`, but only consumes the result inside a dead branch — see the decision memo's consumer inventory row 4.) There is no validation-behavior risk from this feature at all.

The backfill does not merely *reveal* this inconsistency — it **detonates** it.

## Resolution — DECIDED 2026-08-10

**Option 1, allow-list variant.** Fix `otherTaxSum()` to count only `OTHER_TAX`/`OTHER-TAX`; unknown types observable but never counted; `TransactionValidationService::validateAmounts()`/`::validateAmountReconciliation()` textually aligned with the same allow-list for documentation purposes only — both are unreachable dead code, so this carries no runtime behavior or test. Binding details D1-D8 in the [decision memo](decision-t088a-other-tax-semantics.md).

Option 2 (isolate backfilled rows) was rejected: all three candidate mechanisms are defective — a source marker needs an out-of-scope schema change, distinguishing by `created_at` destroys temporal fidelity and breaks FR-014 reconciliation, and joining the audit table puts an N+1 against 3.24M rows inside a per-serialization accessor.

**(Superseded — resolved by D7.)** Formerly open sub-question: the `sc_vat_exempt_sales` column-fallback (`Transaction.php:275-278`). It is live today for window transactions (zero linked rows) and is **disabled** by reconstruction inserting an `SC_VAT_EXEMPT_SALES` row — shifting `net_amount` by the column amount even with the allow-list applied. PITX NET SALES does deduct VAT Exempt Sales, so removal moves away from the formula; retention leaves `otherTaxSum()` a mixed net-sales helper rather than denoting `OTHER_TAX`. Escalated to finance as **S7**, pending quantification of the affected population and peso exposure.

## Cross-references

- `docs/specs/report-vat-correction-coverage.md` — Track B (tax-type alias normalization) owns the long-term fix for the alias half of this divergence. This document records the *`other_tax` component* half, which Track B does not currently cover.
- [spec.md](spec.md) — FR-018, and the T084 finance statement.
- [research.md](research.md) — V4 (orphan census), R3 (column cross-check).
