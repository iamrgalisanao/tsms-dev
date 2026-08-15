# Slice 21 Brief — T058–T061: Post-Run Validation Suite

**Status:** Draft — awaiting sign-off before implementation. Per the approved
plan (`/Users/teamsolo/.claude/plans/use-the-git-workflow-master-agent-quirky-hickey.md`),
this brief exists specifically to pin down tolerance/threshold definitions
before any code is written — these are read-only checks, but they encode
financial-validation pass/fail semantics, the same class of decision this
feature has always briefed first (see slice-19/slice-20).

## Tasks

From [tasks.md](tasks.md):

> - T058: Validate coverage by **date**: per-day `with_tax` ≈ `total` across the window, matching the shape of the V1a table (quickstart Step 6)
> - T059 [P]: Validate by **tenant**: all ~87 affected tenants show corrected totals; none left at zero
> - T060 [P]: Validate by **tax type**: the distribution of reconstructed `tax_type` values is consistent with the post-fix period — a skew indicates a reconstruction bug
> - T061 [P]: Validate by **totals**: reconstructed VAT totals reconcile against `transactions.vat_amount` sums per tenant/month (research R3 cross-check applied in aggregate)

## Grounding (verified against current spec/research, not assumed)

**SC-003 already requires this brief to exist.** Spec.md's SC-003 states aggregate-level components "MUST match within a stated, written tolerance" but never states the number — that gap is exactly what T061 needs to close, not a new requirement invented here.

**FR-009a already established an approved magnitude for "material tax-total difference" in this exact domain**: PHP 500 or 1% of the month's total, whichever triggers first (spec.md:146, "a starting policy chosen to be deliberately inclusive... finance may tune either bound before rollout" — spec.md:204). Reusing this number for T061 rather than inventing a new one keeps one policy definition for "materially different tax total" across the whole feature instead of two competing ones.

**R3 (research.md:48) is a raw-column cross-check, not a rendered-report comparison.** `transactions.vatable_sales`/`vat_amount`/`sc_vat_exempt_sales` were derived from the same original payload as the tax rows, independently of `SalesReportDataService`'s rendering path (which Slice 19's materiality command uses instead). T061 is a direct `SUM(transaction_taxes.amount) WHERE tax_type IN (VAT-mapped types)` vs. `SUM(transactions.vat_amount)` comparison per tenant/month — simpler than materiality's rendered-result approach, and does not need FR-012a's rendering-source pinning (there's no render involved).

**T058's "≈" is resolvable to an exact check, not a fuzzy one.** A transaction either has its full reconstructed tax rows or it doesn't (T008's oracle-verified reconstruction is deterministic). "`with_tax` ≈ `total`" only reads as approximate because of the 216 known-unrecoverable transactions (quarantined, will never have rows). The correct per-day assertion is exact: `with_tax_count == total_count − quarantined_count`, not a percentage band. Treating this as a tolerance question would hide a real reconstruction gap behind a fudge factor; V1a's own table (quickstart Step 6) already reports whole numbers, not percentages.

**T059's "none left at zero" has one real edge case worth naming, not silently ignoring**: a tenant whose *entire* affected population happened to be quarantined (no recoverable transactions at all) would legitimately still show zero backfilled rows post-run, through no defect. This must be classified as `expected_zero` (cross-checked against the quarantine list), not conflated with `unexpected_zero` (a tenant with recoverable transactions that still shows nothing — a real bug).

**T060 is the one check that is genuinely a statistical/policy tolerance, not derivable from an existing decision.** Comparing the backfilled window's `tax_type` distribution against the post-fix reference period's distribution is inherently approximate — business mix varies between periods for reasons unrelated to defects. Proposing a simple percentage-point band per `tax_type` share (see Design below) rather than a formal statistical test, consistent with this feature's established preference for simple, defensible, human-auditable checks over statistically sophisticated ones (FR-014's greedy two-pointer matching was chosen the same way, over more "correct" but opaque alternatives).

## Design

### Decisions (this brief's actual output)

1. **T058 — coverage by date: exact match, zero tolerance.**
   For each day `D` in the window: `with_tax_count(D)` (distinct transactions in `D` with ≥1 `transaction_taxes` row) MUST equal `total_count(D) − quarantined_count(D)` (distinct transactions in `D` with a `tax_backfill_records` row where `outcome='quarantined'`). Any day where these differ is `FAIL` for that day — it means either a reconstructed transaction is silently missing its rows, or a quarantined transaction unexpectedly gained rows (both are bugs, not policy questions).

2. **T059 — coverage by tenant: exact match with one named exception.**
   For each of the ~87 affected tenants: `backfilled_row_count(tenant) > 0` MUST hold, UNLESS every one of that tenant's affected transactions is quarantined (`expected_zero`, verified against the quarantine list, not just asserted). A tenant with `backfilled_row_count == 0` and at least one non-quarantined affected transaction is `unexpected_zero` → `FAIL`.

3. **T060 — tax-type distribution skew: ±5 percentage-point band per type, informational floor to avoid false positives on rare types.**
   For each `tax_type`, compute its share of total reconstructed rows in the backfilled window and its share of total rows in the post-fix reference period (2026-08-10 ~10:00 onward, the same oracle period R4 already uses). Flag `WARN` (not `FAIL` — this is directional evidence of a possible bug, not proof) when a type's share differs by more than **5 percentage points** AND the type has at least **30** rows in both periods (avoids flagging noise on tax types that are simply rare). **This ±5pp/30-row floor is a proposed starting policy, explicitly tunable the same way FR-009a's PHP 500/1% is** — not a hard mathematical result.

4. **T061 — VAT reconciliation: reuse FR-009a's threshold verbatim.**
   Per tenant/month: compare `SUM(transaction_taxes.amount) WHERE tax_type maps to VAT` (using the same payload→column mapping table as R3) against `SUM(transactions.vat_amount)` for that tenant/month. `PASS` if the difference is less than **PHP 500 OR less than 1%** of the month's `transactions.vat_amount` sum, whichever threshold is looser (matching FR-009a's "whichever triggers first" — here read as "whichever is satisfied first," since this is a pass check, not a notify-trigger check). Otherwise `FAIL` — this is the SC-003 "stated, written tolerance" for aggregate exactness, now actually stated.

### Command shape: one bundled command, reusing T076's pattern

**`transactions:tax-backfill-validate`**, not four separate commands. Rationale: T058–T061 share the same window/tenant scoping inputs, are naturally read together as one go/no-go signal (same reason T076 bundled multiple evidence sources into one verdict), and a bundled command lets a future T076 revision reference this command's summary the same way it already references Slice 19's materiality summary, rather than orchestrating four separate command outputs itself.

```
transactions:tax-backfill-validate
    {--from= : Window start (Y-m-d). Required.}
    {--to= : Window end, exclusive (Y-m-d). Required.}
    {--tenant= : Optional, narrows all four checks to one tenant.}
    {--tax-type-skew-band=5 : T060's percentage-point band override.}
    {--tax-type-skew-floor=30 : T060's minimum-row-count floor override.}
    {--json}
```

No `--apply` flag — every check is read-only, matching T076's "nothing to write" command shape. Running it twice with the same window produces the same output.

### Output shape

One result object (this feature's established convention): overall status, then one block per check (`coverage_by_date`, `coverage_by_tenant`, `tax_type_distribution`, `vat_reconciliation`), each with its own `status` (`pass`/`fail`/`warn`) and the specific failing days/tenants/types/tenant-months (not just a count) so a reviewer can act on the output directly, not re-derive it.

Overall status = `fail` if any FAIL exists in any block, else `warn` if any WARN exists (T060 only), else `pass`.

### Not in scope

- T062 (duplicate/null-count check) — already covered by T076's readiness verdict, not duplicated here.
- T080 (tenant-isolation proof) — a distinct, separately-scoped task per its own tasks.md note (T059 checks completeness, not isolation).
- Any remediation action — this command only reports, same as T076.

## Tests

- T058: a day with full coverage passes; a day missing one non-quarantined transaction's rows fails, naming that transaction; a day where a quarantined transaction unexpectedly has rows fails, naming it.
- T059: a tenant with backfilled rows passes; a tenant with zero rows and a non-quarantined affected transaction fails as `unexpected_zero`; a tenant whose entire population is quarantined passes as `expected_zero`, not flagged.
- T060: a tax type within the 5pp band passes; a type exceeding the band with ≥30 rows in both periods warns; a type exceeding the band but under the 30-row floor in either period does not warn (proves the floor works, not just that the band math works).
- T061: a tenant/month within PHP 500 passes; one within 1% but over PHP 500 passes (proves the "whichever is looser" logic, not just a flat PHP 500 cutoff); one exceeding both fails.
- `--tenant=` narrows all four checks correctly.
- `--tax-type-skew-band=`/`--tax-type-skew-floor=` overrides are honored.
- Zero writes to any table, in every scenario (row-count watermark test, matching this feature's established convention).

## Open decision for sign-off

The four numbers above (T058's exactness, the T059 `expected_zero` exception, T060's ±5pp/30-row floor, T061's reuse of FR-009a's PHP 500/1%) are this brief's proposal, not yet a confirmed decision. T058/T059/T061 are grounded in existing approved feature decisions (V1a's exact-count shape, R3's raw-column comparison, FR-009a's threshold) and are low-risk to confirm as-is. **T060's band/floor is the one genuinely new number in this brief** — it has no existing precedent to inherit from, so it's the one most worth an explicit "confirmed" or "adjust to X" before implementation.

## Verification plan (once confirmed)

Implement → targeted tests → full `--filter=Backfill` regression → Pint → Code Reviewer → fix findings → re-verify → update `tasks.md` (T058–T061 `[x]`)/`cli-contract.md` (Command 8) → commit. No Architect drift-revalidation required — read-only, no schema change, no write-path change (not on this feature's High-Risk Gates list), matching T042/reconcile-intake-toggle's own risk classification rather than T076's (which required revalidation because it gates a live financial-data run's go/no-go, not because it's read-only).
