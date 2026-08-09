# Result Handling and Redaction Rules

Rules for what may leave this repository (or be shared outside it) as exported
evidence from the VAT reconciliation package. These apply to every query result,
report, or summary produced from any file in `docs/specs/vat-reconciliation/`.

## Aggregate where possible

Prefer committing/sharing aggregated evidence (counts, sums, percentages, bucketed
distributions) over row-level extracts. Most queries in this package (A2, A3, A4, A6,
A9, A10, B2, B3, B5) are already aggregate by design — use their output directly
rather than re-deriving row-level detail for a report.

## Never persist or share

- Receipt numbers
- Raw transaction payloads (`original_payload` or equivalent)
- Customer details of any kind
- Terminal credentials, tokens, or serial numbers
- Any row-level extract from A1 (`per_transaction_raw_vs_candidate`) beyond what is
  needed for a specific, time-boxed debugging session — A1's output is diagnostic
  scratch data, not a report artifact

## Pseudonymize in shareable reports

When a report leaves the immediate technical working group (e.g., goes into a
finance/business workshop per `report-vat-correction-coverage.md`'s recommended next
sequence), replace raw `tenant_id`/`provider_id` values with stable pseudonyms
(e.g., `Tenant A`, `Provider 2`) unless the specific tenant/provider identity is the
point of the finding (as it legitimately is for the Goldilocks sensitivity analysis
in `70-goldilocks-sensitivity-analysis.sql`, which requires by design that the
Goldilocks tenant be identifiable to whoever is deciding Required Decision 6).

## Keep raw extracts outside Git

Any row-level query output (A1 in particular) belongs in the execution environment's
own scratch/output location referenced by the run's execution manifest
(`execution-manifest-template.md`), never committed to this repository. This
repository holds:

- query definitions (the `.sql` files themselves)
- summarized/aggregated evidence (e.g., a markdown table of A2's output for a
  specific tenant/date range, once reviewed for the rules above)
- the decision-evidence map and execution manifests (metadata about a run, not its
  raw output)

## Commit only query definitions and summarized evidence

If a summarized result set is added to this repository as supporting evidence for a
Required Decision, it must:

1. Contain only aggregated figures, not row-level data
2. Have tenant/provider identifiers pseudonymized unless identity is the finding
   itself (see Goldilocks exception above)
3. Be accompanied by (or reference) its execution manifest
4. Be labeled with which Required Decision(s) it supports, per
   `decision-evidence-map.md`
