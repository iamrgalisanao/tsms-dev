# Request for Input — Backfill Transaction Taxes (002)

## Status

**Engineering Gate 0 is complete**: `ARCHITECTURE_APPROVED` ✅ · `IMPACT_ANALYZED` ✅ · `BASELINE_RECORDED` ✅. No code has been written; no live data has been touched.

**Update 2026-08-11 — all six items decided.** S1, S2, S4, S5, and S6 were decided today (S3 was decided earlier the same day). `READY_TO_IMPLEMENT` is cleared as a stakeholder-decision matter — implementation may proceed once the remaining engineering tasks that carry these decisions forward (T084, T086, T088a-1, T088b, per `tasks.md`) are actually completed. Full technical detail lives in `specs/002-backfill-transaction-taxes/`; this page exists so no one has to reconstruct that history to answer these questions.

---

## S4 — DECIDED 2026-08-11: PITX formula worksheet provenance

**No longer blocking.** PITX/Finance is confirmed as the formula owner, and the PITX computation screenshot supplied during architecture review is accepted as the source-of-record for this decision (GROSS / NET SALES / VAT formulas in `other-tax-semantics.md`). This closes the provenance gap that D1–D8 and FR-018 depended on.

**Follow-up (engineering, T088b, not a stakeholder question)**: preserve the screenshot as tracked evidence — either commit it (or a faithful transcription) into the repo alongside `other-tax-semantics.md`, or attach it to this spec directory — so the business-rule authority is not resting on an untracked image. The transcription in `other-tax-semantics.md` remains the working reference; T088b's remaining job is making the source itself durable, not re-deciding anything.

---

## S1 — DECIDED 2026-08-11: Finance/PITX re-sign-off

**No longer blocking.** Approved to proceed as a **controlled data remediation**, not an emergency dashboard fix. The backfill is justified independently of finance's day-to-day monitoring habits: source-of-truth linkage (`transaction_pk` integrity), auditability, reporting consistency, and unblocking future `transaction_pk NOT NULL` schema hardening all hold regardless. The corrected impact statement (real `other_tax` delta larger than the original, false-premise sign-off implied; effect non-uniform across tenant-days) is acknowledged as part of this approval.

**Tenant communication policy**: notification is **materiality-based only** — decided per the FR-012 pre-backfill snapshot and FR-009a's materiality threshold, not as a blanket announcement. No tenant comms are triggered by this decision alone.

---

## S5 — DECIDED 2026-08-11: VAT-exempt / `other_tax` semantics (principle confirmation)

**No longer blocking.** Confirmed: ingestion remains **passive** (no mutation or recalculation at intake). `other_tax` means **only** submitted `OTHER_TAX`/`OTHER-TAX` values — matching D7's allow-list design exactly. VAT-exempt sales is a **separate PITX deduction** and must **not** be folded into `otherTaxSum()`. This affirms the mechanism D7 already implements (no PHP 13.8M swing, per the S7 quantification in `decision-t088a-other-tax-semantics.md`) — finance's principle confirmation closes the last open question against it. No design change required.

---

## S2 — DECIDED 2026-08-11: `tax_exempt` boolean-as-currency disposition

**No longer blocking.** Decision: **exclude `transactions.tax_exempt` from exact currency assertions and materiality math** (SC-003, FR-009a) for this feature. It is a boolean, not a peso amount, and the pre-existing defect is **not** fixed or mutated as part of this backfill — it is tracked as a separate defect for its own ticket. This matches the recommendation that was on file (option (a)).

---

## S3 — DECIDED 2026-08-11: disposition of the 216 unrecoverable transactions' orphan rows

**No longer blocking.** Originally asked whether these rows should be retained live in `transaction_taxes` forever. **Decision reached**: archive them (durable, queryable, with full reconciliation metadata and reason code `no_replacement_exists`) and then **delete them from the live table**, same treatment as every other day's orphans — preservation is satisfied by a verified archive, not by permanent live retention. This also removes a permanent NULL-keyed residue from the operational table and keeps `transaction_pk NOT NULL` achievable as a future hardening step.

**Guardrail carried into the spec** (FR-015b): deletion of this residual is gated on the same two conditions as every reconciled day — archive-write verified successful, and residual count verified to equal exactly 216 transactions' worth of rows — before any live delete. Archive-before-delete remains mandatory; a failed verification blocks deletion, full stop.

**Residual, non-blocking question** if anyone owns data-retention policy: how long should the *archive table itself* retain this data (and every other archived orphan)? Default assumption is indefinite retention unless told otherwise — this does not block implementation.

---

## S6 — DECIDED 2026-08-11: API net-sales fields (`net_amount` / `calculated_net_sales`)

**No longer blocking, resolved by design decision rather than by an external-consumer answer.** `calculated_net_sales` is confirmed as the **canonical PITX-derived** API/reporting value. Going forward, `net_amount` uses the **same calculation as a compatibility alias** — the field is kept for backward compatibility, but its value is the corrected one, not a second diverging formula. Raw POS-submitted transaction facts remain untouched (ingestion stays passive, per S5).

This supersedes the original framing of this gate (an open factual question about whether a specific external consumer reads these fields via `POST /api/v1/transactions/{transaction_id}/refund` — see T088a-1 in `tasks.md`). The decision proceeds without waiting on that answer: both fields will carry the corrected value regardless of who consumes them.

---

## Status: all decided

All six items (S1–S6) are decided as of 2026-08-11. Nothing remains on this page as an open stakeholder question. Remaining work is engineering execution of these decisions (T084, T086, T088a-1, T088b in `tasks.md`) and the standard slice-loop review process — not further stakeholder input.
