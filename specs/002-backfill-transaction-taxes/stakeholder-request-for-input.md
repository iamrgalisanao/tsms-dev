# Request for Input — Backfill Transaction Taxes (002)

## Status

**Engineering Gate 0 is complete**: `ARCHITECTURE_APPROVED` ✅ · `IMPACT_ANALYZED` ✅ · `BASELINE_RECORDED` ✅. No code has been written; no live data has been touched.

**`READY_TO_IMPLEMENT` is blocked until all six items below are answered.** These are stakeholder decisions, not engineering work — nothing on this list can be resolved by more analysis. Full technical detail lives in `specs/002-backfill-transaction-taxes/`; this page exists so no one has to reconstruct that history to answer these six questions.

---

## S4 — PITX formula worksheet provenance

| | |
|---|---|
| **Owner** | Whoever produced/owns the PITX computation worksheet (GROSS / NET SALES / VAT formulas) |
| **Question** | Who authored this worksheet, when, what version, and is it approved business policy or a working draft? |
| **Why it matters** | This worksheet is the **sole source of truth** for every `other_tax`/VAT-exempt decision in this feature (D1–D8, FR-018). It currently has no owner, date, or approval status on record. |
| **Acceptable answer / evidence** | Either (a) the worksheet is confirmed and signed by its owner and promoted to a tracked repo document, or (b) `other-tax-semantics.md` (the written summary of it) is reviewed and countersigned by the owner. |
| **Blocks** | Everything — S1, S2, S5 all inherit this worksheet's authority. Highest priority. |

---

## S1 — Finance sign-off (re-confirmation)

| | |
|---|---|
| **Owner** | Finance |
| **Question** | Confirm the corrected impact statement below and state whether it changes priority or requires tenant communication. |
| **Why it matters** | A prior sign-off was obtained on a **false premise** (that a payload fallback covered `other_tax` — it doesn't) and has been formally withdrawn. Finance approved a smaller impact than the real one. |
| **Acceptable answer / evidence** | Written confirmation covering: (1) `other_tax` was not backfilled by any fallback — real impact is larger than previously stated; (2) the effect is **non-uniform across tenant-days**, not a flat correction; (3) whether this changes rollout priority or requires notifying tenants. **Note**: the underlying mechanism risk (a PHP 13.8M swing) has already been engineered away — see S5, this is a smaller ask than it sounds. |
| **Blocks** | Any live `--apply` run. |

---

## S5 — VAT-exempt alias principle (narrowed finance question)

| | |
|---|---|
| **Owner** | Finance |
| **Question** | *"Should TSMS continue deducting VAT-exempt sales from the API-visible `net_amount`/`calculated_net_sales` fields, per the PITX formula?"* |
| **Why it matters** | The mechanism question is **already closed** by design decision D7 — there is no PHP 13.8M swing to approve either way. This is a pure principle check: does PITX's "yes, deduct VAT-exempt" rule still hold for these two fields. |
| **Acceptable answer / evidence** | Yes/no. If no, or if it should apply only to some tenants/aliases, say so — that reopens a design conversation, but the current default (yes, per PITX) ships without further input. |
| **Blocks** | Finalizing D7's accessor formula. Low effort — this is a confirm-or-object question, not new analysis. |

---

## S2 — FR-016: `tax_exempt` boolean-as-currency disposition

| | |
|---|---|
| **Owner** | Architecture (technical decision), informed by Finance if it changes reported figures |
| **Question** | `transactions.tax_exempt` is a **boolean** but gets summed as if it were a peso amount in one `other_tax` code path. How should this feature treat that pre-existing defect for the purposes of SC-003 (exactness) and FR-009a (materiality)? |
| **Why it matters** | Without a decision, the "before" baseline used for materiality calculations is partly a *count of transactions*, not a trustworthy currency figure — which would corrupt every downstream materiality/exactness assertion. |
| **Acceptable answer / evidence** | One of: (a) exclude this term from exact-match assertions and document the gap, or (b) fix the underlying defect (out of scope for this feature, would need its own ticket). Recommendation on file: (a) — fixing it is a separate, pre-existing bug. |
| **Blocks** | SC-003 and FR-009a cannot be finalized without this. |

---

## S3 — Disposition of the 216 unrecoverable transactions' orphan rows

| | |
|---|---|
| **Owner** | Whoever owns data-retention/compliance policy for this class of record |
| **Question** | Formally confirm: the orphan tax rows for the 216 transactions on 2026-06-13 that have no recoverable payload — the **only surviving record** of their tax lines anywhere — should be archived and **retained permanently**, never deleted. |
| **Why it matters** | This is a small population (0.03%) but a permanent, non-reversible decision. Deleting them destroys the only copy of those transactions' tax data forever. |
| **Acceptable answer / evidence** | Confirmation of the default already directed (retain, don't delete), or an explicit override with reasoning. This task is a **formal record**, not a request to reconsider — it exists so the decision is documented, not implied. |
| **Blocks** | FR-015b's implementation; low effort, mostly a rubber-stamp unless there's an objection. |

---

## S6 — External consumer check for `$appends` fields

| | |
|---|---|
| **Owner** | Whoever knows the POS provider integration surface / API consumer base |
| **Question** | Does any POS provider or client consume `net_amount` or `calculated_net_sales` from `POST /api/v1/transactions/{transaction_id}/refund`'s response? |
| **Why it matters** | Fixing the underlying defect changes these two fields' values for transactions outside the defect window that already have linked tax rows. Nothing in-repo can answer whether an external consumer depends on the current (wrong) values. |
| **Acceptable answer / evidence** | A yes/no from whoever has visibility into provider integrations or API logs/analytics for that endpoint. If yes, name the consumer so impact can be scoped; if no, this closes immediately. |
| **Blocks** | Shipping the `otherTaxSum()` fix — this is the fix's only unresolved residual risk. |

---

## Priority order (recommended)

**S4 first** — it's the foundation everything else cites. **S1 next** (now a narrower ask than originally proposed, thanks to S5's mechanism fix). Then S2, S3, S5, S6 in any order — none blocks the others.
