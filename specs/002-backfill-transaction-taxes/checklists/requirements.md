# Specification Quality Checklist: Backfill Transaction Taxes

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-10 · **Last revised**: 2026-08-10 (after Architect pass 3)
**Feature**: [spec.md](../spec.md)

> **`ARCHITECTURE_APPROVED` (pass 5) · `IMPACT_ANALYZED` (pass 7) · `BASELINE_RECORDED` · `READY_TO_IMPLEMENT`, all done (2026-08-11).** All Phase 0B stakeholder gates (S1-S6) are decided — see `stakeholder-request-for-input.md`. Remaining open items below are engineering follow-through (audit-trail capture, evidence preservation, small implementation changes), not further stakeholder input. This checklist previously read fully green while FR-016 was undecided — that was stale and misleading; the state below is accurate.

## Content Quality

- [x] No implementation details in the requirements themselves
- [x] Focused on user value and business needs
- [ ] Written for non-technical stakeholders — **degraded**: the spec now carries substantial defect forensics (FR-016 boolean-as-currency, FR-018 accessor semantics) that a non-technical reader cannot evaluate
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No `[NEEDS CLARIFICATION]` markers remain
- [ ] Requirements are testable and unambiguous — **FR-016 decided 2026-08-11 (S2)**, no longer a gap here; **D3 observability** is specified but still untested (a test-coverage gap, not a stakeholder-decision gap)
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria — FR-016 decided 2026-08-11 (S2)
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes — SC-002's permanent-residue qualifier is **resolved 2026-08-11** (zero `transaction_pk IS NULL` rows is now the target end state, FR-015b); SC-003's exclusion of `other_tax` is now a settled decision (S2), not an open gap
- [x] No implementation details leak into the specification

## Blocking items

**Stakeholder decisions — none remain.** All resolved 2026-08-11: ~~S1 finance re-sign-off~~ **DECIDED** (T084) · ~~S2 FR-016 disposition~~ **DECIDED** (T086) · ~~S3 216-row retention record~~ **DECIDED** (archive-and-delete, T085/FR-015b) · ~~S4 PITX worksheet provenance~~ **DECIDED** (T088b) · ~~S5 VAT-exempt/`other_tax` principle~~ **DECIDED** (also closes S7's principle-confirmation ask, mechanism already closed by D7) · ~~S6 API net-sales fields~~ **DECIDED** (T088a-1, `net_amount` becomes a compatibility alias of `calculated_net_sales`).

**Engineering follow-through remaining**: T088a-2/-2b/-3 (allow-list implementation), T088a-1 (alias implementation), T084/T086/T088b (audit-trail/evidence capture for already-made decisions), plus the pre-gate baseline and staging schema confirmation.

## Notes

- The business case was re-baselined after the original framing ("reports show zeros") proved wrong; the driver is now source-of-truth integrity. The 2026-08-10 finance sign-off obtained against the *old* statement was withdrawn and **re-confirmed 2026-08-11** against the corrected statement (S1).
- Provenance risk, resolved: the PITX formula worksheet's ownership question is closed (S4, T088b) — the screenshot is accepted as source-of-record. Preserving it as a tracked repo artifact (rather than an untracked image) remains as T088b's residual engineering work.
