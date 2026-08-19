Title: Aggregate-level VAT-inclusive `vatable_sales` normalization is unstable and produces internally inconsistent CMSR visible columns

Component: `app/Services/Reports/FinanceCalculationService.php` (`deriveMetrics()`)
Reported: 2026-08-19
Status: Primary defect FIXED (branch `002-backfill-transaction-taxes`, uncommitted). Secondary defect still open.
Severity: High (finance-facing reporting output; tenant-visible)
Date discovered on: staging `10.20.0.41` (`/var/www/PITX/tsms-dev`), tenant 106 (Mang Inasal), 2026-08-15 .. 2026-08-18

Summary
-------
`deriveMetrics()` decides *at aggregate level* whether a summed `vatable_sales` is VAT-inclusive and needs
rewriting to a VAT-exclusive figure. The decision is gated on **absolute peso tolerances** (`0.05`, `0.10`,
`1.00`) applied to whole-day sums. Per-transaction rounding residue accumulates with row count, so the same
tenant with the same upstream data convention flips between the rewritten and non-rewritten branch on
adjacent days.

When the rewrite does not fire, the exported `vatable_sales` column stays VAT-inclusive while `vat_amount` is
still exported as its own column. Any consumer that reconciles by summing the visible columns therefore
double-counts VAT for that day.

This is **not** a data problem. Raw stored `vatable_sales` is VAT-inclusive on all four days examined, and
stored transaction totals were verified to match `original_payload`. No transaction backfill is required.

Evidence
--------
Raw = `SUM(t.vatable_sales)` for the day. Export = `deriveMetrics()['vatable_sales']` as written to the CMSR
sheet. Gate = `abs($rawVat - $aggregateVat)`, the `<= 1.00` test at `FinanceCalculationService.php:314-319`.

| Date       | Raw vatable | Export vatable | VAT gate | Rewritten | Visible-column vs report gross delta |
|------------|------------:|---------------:|---------:|-----------|-------------------------------------:|
| 2026-08-15 |  309,611.40 |     309,611.40 |     9.38 | no        |                            33,182.03 |
| 2026-08-16 |  312,820.18 |     312,820.18 |     3.27 | no        |                            33,519.72 |
| 2026-08-17 |  293,033.33 |     261,636.90 |     0.21 | yes       |                                 0.00 |
| 2026-08-18 |  229,945.92 |     205,308.86 |     0.08 | yes       |                                 0.00 |

The arithmetic closes exactly, which confirms the mechanism rather than merely correlating with it:

- On the rewritten days the export equals `raw / 1.12` to the centavo
  (293,033.33 / 1.12 = 261,636.90; 229,945.92 / 1.12 = 205,308.86).
- On the non-rewritten days the reconciliation delta equals the VAT component of the raw figure
  (`raw - raw/1.12`) **plus the gate residue**:
  - 2026-08-15: 33,172.65 + 9.38 = 33,182.03
  - 2026-08-16: 33,516.45 + 3.27 = 33,519.72

i.e. the delta *is* the double-counted VAT, and the amount by which the gate was missed is exactly the amount
by which the day's accumulated rounding exceeded the ±1.00 allowance.

Root cause
----------
`FinanceCalculationService::deriveMetrics()`:

- `:306` — `$reportedVatableSales` is initialised to the **raw summed** `vatable_sales`.
- `:309-312` — two detection predicates compare aggregate sums with a `<= 0.05` absolute tolerance.
- `:313-319` — `$capturedSplitIsRoundingOnly` requires, in addition, that the reported aggregate VAT match a
  recomputed `derivedNetSales / 1.12 * 0.12` within **`<= 1.00` pesos**.
- `:326-329` — only if that composite condition holds is `$reportedVatableSales` rewritten to the VAT-exclusive
  `derivedNetSales - $vat`.

All three tolerances are absolute constants evaluated against day-level aggregates. Per-receipt VAT is rounded
to 2dp at the POS; summing thousands of receipts accumulates residue that scales roughly with transaction
count, and routinely exceeds ₱1.00 on a normal trading day. The tolerance was evidently calibrated for
single-receipt or small-batch magnitudes.

Note also that this heuristic runs *after* aggregation for every caller — there is no per-transaction
normalization path anywhere in the reporting stack.

Why existing test coverage does not catch it
--------------------------------------------
`tests/Unit/FinanceCalculationServiceTest.php:10` (`test_csmr_normalizes_vat_inclusive_vatable_sales`) asserts
the rewrite happens — but its fixture is a single receipt-scale aggregate (`gross_sales = 15,311.14`) built
from internally exact figures, so `abs($rawVat - $aggregateVat)` evaluates to **0.00** and the ±1.00 gate is
satisfied trivially. The test proves the rewrite path works; it cannot detect that the gate is unreachable at
production magnitudes.

There is no test that runs the same convention through two aggregates of differing row count / rounding
residue and asserts they land on the same branch.

Secondary defect (independent, also open)
-----------------------------------------
The rewrite at `:326-329` mutates only `$reportedVatableSales`. The reconciliation figures are built from
different internal values:

- `$rawComponentSum` (`:237`) uses `$rawVatableSales`.
- `$normalizedComponentSum` (`:253`) uses `$vatableForGross` — a *separately* derived value computed at
  `:202-232` under its own distinct set of heuristics.
- `computed_gross_sales` / `gross_sales_variance` are derived from those, never from `$reportedVatableSales`.

So the exported `vatable_sales` column and the exported `computed_gross_sales` / `gross_sales_variance`
reconciliation fields can be built from three different interpretations of the same input. An exported row can
be internally inconsistent by construction, independently of whether the ±1.00 gate fires. Fixing the gate
alone will not make the visible columns self-consistent.

**Blocker on the obvious fix — different tenants report `gross_sales` on different bases.** Feeding the
normalized VAT-exclusive value into the component sums is not safe as a blanket change:

- Staging tenant 106 reports gross on the **exclusive** basis:
  `276,438.75 + 33,182.03 + exempt + discounts = 333,136.25` (2026-08-15).
- The fixture in `tests/Unit/FinanceCalculationServiceTest.php:38` reports gross on the **inclusive** basis:
  `net_sales (85,069.62, VAT-inclusive) + vat (8,692.94) + discounts = 94,746.54`, i.e. that POS's own
  reported gross already double-counts VAT relative to the worksheet rule.

Switching the component sums to the normalized basis flips that fixture's `gross_sales_variance` from `0.00`
to `-8,692.88`. Resolving this defect therefore requires deciding how per-tenant gross basis is detected or
configured, and is not a mechanical change. Tracked separately.

Blast radius
------------
`deriveMetrics()` callers, all passing `['gross_sales_basis' => 'pre_deduction']`:

- `app/Http/Controllers/Reports/CommercialReportsController.php:1135` — CMSR JSON + XLSX export
  (`vatable_sales` → sheet column F, `gross_sales` → column D).
- `app/Http/Controllers/TransactionLogController.php:916` (grand total) and `:1004` (per-row summary) —
  Transaction Logs dashboard.
- `app/Services/Reports/SalesReportDataService.php:175, :182, :277, :280` — consumed by `DailyReportService`,
  `WeeklyReportService`, and `Finance/SalesReportExportController`.
- `app/Services/Reports/HourlyReportService.php:64`.

Every one of these is affected; the defect is not specific to CMSR or to tenant 106. Smaller aggregation
buckets (hourly) will flip branches more often than larger ones, in either direction, because the residue
scales with the bucket's row count while the tolerance does not.

**Precondition and known limitation — mixed-convention buckets are outside what this fix can correct.**
The classifier assumes the aggregate it is handed represents a *single* reporting convention. Several call
sites do not guarantee that:

- `HourlyReportService.php:62` groups by hour only (`groupBy(fn ($tx) => $this->reportHour($tx))`); the tenant
  filter at `:50` applies only when a `tenantId` is supplied.
- `SalesReportDataService.php:182, :280` derive a grand total from components summed across every day and
  tenant in scope.
- `TransactionLogController.php:916` is likewise an unscoped grand total.

A bucket blending tenants that report on different bases produces a weighted-average ratio sitting between the
anchors, and no aggregate-level test can recover the correct value from it. The corroboration requirement
limits the damage — a blended bucket usually fails to corroborate and is left alone — but the underlying
exposure is not fixed here and was not fixed before.

Policy resolution (settled 2026-08-19)
-------------------------------------
The `PITX CMSR` worksheet computes VAT as `Vatable Trans. * 12%` and Gross Sales per receipt as `=SUM(B:M)`,
which spans both column B and the VAT column. Column B must therefore be **VAT-exclusive**; a VAT-inclusive B
makes the worksheet double-count VAT when it reconstructs Gross. Confirmed against three worksheet receipts,
where `B * 12%` reproduces the VAT column to the centavo and `B * 1.12` lands on clean POS figures:

| si_no | B (vatable) | B x 12% | VAT column | B x 1.12 | SUM(B:M) |
|-------|------------:|--------:|-----------:|---------:|---------:|
| 1     |      238.10 |   28.57 |      28.57 |   266.67 |   861.90 |
| 2     |      267.86 |   32.14 |      32.14 |   300.00 |   326.79 |
| 3     |      321.43 |   38.57 |      38.57 |   360.00 |   432.53 |

**Decision: report-facing `vatable_sales` is VAT-exclusive.** This is the BIR taxable base and the basis the
consuming worksheet already assumes.

Fix applied (primary defect)
----------------------------
`FinanceCalculationService::normalizeVatableToExclusive()` replaces the absolute-peso gate with a **ratio
discriminator**. Given `ratio = reported_vat / reported_vatable`:

- a VAT-exclusive base sits at `0.12`
- a VAT-inclusive base sits at `0.12 / 1.12 = 0.107143`

The anchors are `0.0128571` apart — 10.7% in relative terms — while accumulated per-receipt rounding moves the
ratio by parts per million. The classification is therefore scale-free, where the old peso gate's headroom was
consumed by row count.

Measured drift from the nearest anchor, against every existing fixture and all four staging days:

| Fixture | ratio | drift | classification |
|---|---:|---:|---|
| unit test 1 | 0.1071427 | 0.0000002 | inclusive |
| unit test 2 | 0.1071435 | 0.0000007 | inclusive |
| unit test 3 | 0.1200000 | 0.0000000 | exclusive |
| staging 08-15 | 0.1071732 | 0.0000303 | inclusive |
| staging 08-16 | 0.1071533 | 0.0000105 | inclusive |
| staging 08-17 | 0.1071429 | 0.0000000 | inclusive |
| staging 08-18 | 0.1071428 | 0.0000000 | inclusive |

Worst-case drift is 424x smaller than the anchor separation. The same-day comparison that previously failed
(Aug 15, peso gate missed by 9.38) now classifies with 0.0000303 of drift against 0.0064 of headroom.

### The ratio test is necessary but not sufficient — corroboration is required

A ratio test alone cannot separate a genuinely VAT-inclusive base from a VAT-exclusive base **diluted by
exempt or zero-rated content**. If a share `s` of the vatable column is not actually taxable, the observed
ratio falls to `0.12 * (1 - s)`, which enters the VAT-inclusive match window for **s between 5.71% and
15.71%**. A first cut of this fix, classifying on the ratio alone, silently wrote such a base down by 10.71%:

| exempt share inside vatable | ratio | ratio-only classifier | correct |
|---|---:|---|---|
| 4.0% | 0.115200 | 300,000.00 (exclusive) | ok |
| 8.0% | 0.110400 | **267,857.14** | 300,000.00 |
| 14.0% | 0.103200 | **267,857.14** | 300,000.00 |
| 18.0% | 0.098400 | 300,000.00 (unmatched) | ok |

This is a realistic shape, not a hypothetical: `ZERO_RATED` appears in `NON_OTHER_TAX_TYPES` but **not** in
`EXEMPT_TAX_TYPES`, so `aggregateComponents()` routes zero-rated sales to neither `other_tax` nor
`sc_vat_exempt_sales`. The existing `$capturedVatableIncludesVatLessSeniorPwd` heuristic also exists precisely
because at least one observed POS reports `vatable` net of senior/PWD. The distortion is one-directional:
dilution only moves the ratio down, so the misclassification is always an *understatement*.

The old absolute gate, for all its instability, cross-checked the reported split against `$derivedNetSales` —
it had corroboration. Replacing it with a corroboration-free test would have traded a visible, reconcilable
error for a silent 10.71% one.

**The shipped classifier therefore requires two independent conditions before rewriting anything:**

1. **Ratio test** — `vat / vatable` matches the VAT-inclusive anchor within tolerance.
2. **Corroboration** — the raw component sum overshoots the POS-reported `gross_sales` by approximately the
   VAT amount, i.e. the double-count is actually observable. Tolerance is relative (2% of VAT, floor PHP 1.00)
   so it scales with the aggregate.

A rewrite therefore only ever happens where it demonstrably improves reconciliation against the POS-reported
gross, never on the strength of a two-number coincidence.

The returned key `vatable_sales_basis` exposes the decision for auditing: `exclusive`,
`normalized_from_inclusive`, `unmatched` (ratio matched neither anchor), `uncorroborated` (ratio said
inclusive, gross disagreed), `not_applicable` (no usable vatable/VAT pair).

Scope deliberately held (per decision 2026-08-19):

- Only `vatable_sales` normalization changed.
- `gross_sales` still returns raw POS gross.
- `computed_gross_sales` / `gross_sales_variance` untouched — see secondary defect below.
- `vat_amount` and `net_ex_vat` remain on the legacy aggregate gate (`$capturedSplitIsRoundingOnly`). That
  gate moves `vat_amount` by at most PHP 1.00 by construction, so it is immaterial, but it is still
  nondeterministic in the same way and is follow-up scope.

`net_ex_vat` required explicit care. It was previously assigned the same value as `vatable_sales` inside the
legacy branch, so letting it inherit the normalized figure would have moved it — and with it
`net_subject_to_rent`, the **percentage-rent basis billed to tenants** (`net_sales_percentage_rent` in
`HourlyReportService.php:88`, cell `N71` in `SalesReportExportController.php:139`). It is now re-derived
locally from `$derivedNetSales - $vat`, reproducing the pre-fix value exactly, with a comment warning against
re-collapsing the two. A 200,000-case randomized comparison of the pre-fix and post-fix implementations
confirms **no returned key other than `vatable_sales` differs anywhere**, so the "only `vatable_sales` changed"
claim is measured rather than asserted.

Effect on staging tenant 106 (visible-column vs report-gross delta):

| Date | before | after |
|------|-------:|------:|
| 2026-08-15 | 33,182.03 | 9.38 |
| 2026-08-16 | 33,519.72 | 3.27 (predicted; see note) |
| 2026-08-17 |      0.00 | 0.00 (unchanged — value identical to today's) |
| 2026-08-18 |      0.00 | 0.00 (unchanged — value identical to today's) |

**Note on 2026-08-16.** Under the corroboration requirement this row is a prediction, not a measurement. Only
08-15 has a full gross/exempt/discount reconciliation recorded here, and it corroborates exactly (overshoot
matches VAT to 0.00 against a PHP 663.64 tolerance). 08-16 has the same ratio signature but its gross
breakdown was not captured, so whether it corroborates should be confirmed against staging alongside the CI
run. If it does not, it will read `uncorroborated` and stay VAT-inclusive rather than silently mis-normalize.

A second, narrower residual path is worth recording: the overshoot test cannot distinguish "vatable
double-counts VAT" from "gross omits VAT", since both leave `rawComponentSum - gross` near the VAT amount. A
tenant reporting gross EXCLUDING VAT *and* carrying 5.7-15.7% exempt/zero-rated content inside the vatable
column would still be written down 10.71%. Neither condition is attested in any fixture or staging day
examined -- tenant 106's own reconciliation has gross including VAT -- so this is recorded as a known
one-sidedness of the guard rather than an open defect.

The residual 9.38 / 3.27 is the accumulated per-receipt rounding itself (0.003% of gross). It cannot be driven
to zero by any aggregate-level approach; only per-transaction normalization before aggregation would remove
it. That remains the preferred long-term direction, unchanged.

**Expected visible change — not a regression.** Transaction Logs / dashboard `vatable_sales` values will
change for tenants and dates that were previously stuck on the un-rewritten branch. The corrected value has a
defensible definition (VAT-exclusive taxable base) and now agrees with the CMSR export for the same tenant and
date. Scoping the fix to CMSR alone was considered and rejected: it would have left the unstable gate live on
four of five surfaces and created "CMSR says X, dashboard says Y" disputes.

Persistence contract
--------------------
Because `SalesReportResult::toArray()` is persisted in pre-backfill snapshots and materiality evidence, this
change also bumps the CMSR report contract from `cmsr_v1` to `cmsr_v2` for newly captured snapshot and
materiality runs. The materiality report only auto-selects v2 snapshots/runs. If an operator explicitly pairs
an existing `cmsr_v1` snapshot with a v2 materiality run, the pair is recorded as `contract_mismatch` with
null deltas and the after-rendered result retained for audit, mirroring the existing source-mismatch handling
rather than silently comparing incompatible report shapes.

Verification performed
----------------------
PHPUnit could **not** be executed in the local environment (no MySQL service, no Docker; the sqlite fallback
fails because migrations such as `2025_07_04_000019_create_circuit_breakers_table.php` use MySQL-specific
`ALTER TABLE ... ADD CONSTRAINT` syntax). `deriveMetrics()` is a pure function, so every assertion in
`tests/Unit/FinanceCalculationServiceTest.php` — the four pre-existing ones and the six added — was executed
directly against the patched service and passes. In addition, the pre-fix implementation was extracted from
git and run side by side with the patched one over a 200,000-case randomized sweep to confirm that no returned
key other than `vatable_sales` differs. **The suite still needs a run in CI, where MySQL is available.** Pint
is clean on both changed files.

Remediation direction for the remaining work
--------------------------------------------
1. ~~Stop using the aggregate-level `<= 1.00` VAT rounding gate.~~ Done.
2. Still preferred long-term: normalize per transaction, before aggregation, so the aggregate is a sum of
   consistently-based values. This removes the residual rounding delta (9.38 / 3.27 above) that no
   aggregate-level approach can eliminate.
3. Move `vat_amount` / `net_ex_vat` off the legacy `$capturedSplitIsRoundingOnly` gate.
4. Resolve the secondary defect — but see the blocker recorded under it.

Explicitly out of scope
-----------------------
- **No backfill of Aug 15-16 raw transaction data.** Stored values are correct and match `original_payload`.
  The raw convention is uniform across all four days; only the reporting layer's interpretation varies.

Regression coverage added
-------------------------
All in `tests/Unit/FinanceCalculationServiceTest.php`:

- `test_vatable_normalization_is_stable_across_differing_rounding_residue` — the real staging aggregates for
  2026-08-15 and 2026-08-17. Same tenant, same convention, non-proportional, with ratio drift of 0.0000303 vs
  0.0000000. Instrumented against the pre-fix implementation these two land on *different* bases
  (309,611.40 left VAT-inclusive vs 261,636.90 normalized), so the test genuinely fails on the old code.
- `test_csmr_does_not_normalize_vat_inclusive_ratio_without_gross_corroboration` — the exempt-contamination
  shape; pins the corroboration requirement.
- `test_csmr_leaves_already_vat_exclusive_vatable_sales_untouched`
- `test_csmr_leaves_vatable_sales_untouched_when_ratio_matches_neither_basis`
- `test_csmr_does_not_classify_a_taxable_base_reported_without_vat`
- `test_vatable_normalization_does_not_move_percentage_rent_basis` — pins `net_ex_vat` and
  `net_subject_to_rent` at their pre-fix values.
- `test_csmr_normalizes_vat_inclusive_vatable_sales` retained unchanged; it still returns 13,061.61.

A caution for anyone extending these: a "large vs small" pair built by scaling one fixture by a constant is
worthless as a stability test. The ratio is then identical to the last bit, so a ratio-based classifier
cannot fail it. The fixtures must differ in *residue*, not merely in magnitude. An earlier draft of this
change shipped exactly that mistake — two fixtures related by a factor of 16 — and the assertion that named
the property was tautologically true.

Open decisions
--------------
- ~~Which basis is authoritative for the tenant-facing `vatable_sales` column?~~ **Settled 2026-08-19:
  VAT-exclusive.** See Policy resolution above.
- Whether previously issued CMSR exports for affected periods need reissue, and for which tenants/date ranges.
- How per-tenant `gross_sales` basis should be detected or configured — gates the secondary defect.
- Whether `HourlyReportService` should group by tenant as well as hour, so hourly buckets cannot blend
  reporting conventions.
