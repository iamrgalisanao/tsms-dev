# Specification Quality Checklist: Backfill Transaction Taxes

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-08-10 · **Last revised**: 2026-08-10 (after Architect pass 3)
**Feature**: [spec.md](../spec.md)

> **`ARCHITECTURE_APPROVED` (pass 5) · `IMPACT_ANALYZED` (pass 7) · `BASELINE_RECORDED`, all done. `READY_TO_IMPLEMENT` NOT yet cleared** — blocked on the Phase 0B stakeholder gates (S1-S6), not on engineering work. This checklist previously read fully green while FR-016 was undecided — that was stale and misleading; the state below is accurate.

## Content Quality

- [x] No implementation details in the requirements themselves
- [x] Focused on user value and business needs
- [ ] Written for non-technical stakeholders — **degraded**: the spec now carries substantial defect forensics (FR-016 boolean-as-currency, FR-018 accessor semantics) that a non-technical reader cannot evaluate
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No `[NEEDS CLARIFICATION]` markers remain
- [ ] Requirements are testable and unambiguous — **FR-016 undecided** (T086); **D3 observability** now specified but untested
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [ ] All functional requirements have clear acceptance criteria — FR-016 pending
- [x] User scenarios cover primary flows
- [ ] Feature meets measurable outcomes — SC-002 qualified for permanent residue; SC-003 excludes `other_tax` pending FR-016
- [x] No implementation details leak into the specification

## Blocking items

**Stakeholder decisions**: S1 finance re-sign-off (T084) · S2 FR-016 disposition (T086) · S3 216-row retention record (T085) · S4 PITX worksheet provenance (T088b) · S5 alias sub-question · S6 external `$appends` consumer check (T088a-1) · S7 **reduced to a principle confirmation** (quantified: PHP 13.8M / 69 tenants; mechanism closed by D7)

**Engineering**: T088a-2/-2b/-3 (allow-list implementation), plus the pre-gate baseline and staging schema confirmation.

## Notes

- The business case was re-baselined after the original framing ("reports show zeros") proved wrong; the driver is now source-of-truth integrity. Finance sign-off obtained against the *old* statement is **withdrawn**.
- Provenance risk: the PITX formula worksheet is the sole business-rule authority and is not yet a tracked document (T088b). The archived payload guidelines it corrects are gitignored and were never committed.
