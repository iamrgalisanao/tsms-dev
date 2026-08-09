# Standard Status Gates

Use these exact machine-readable statuses in agent outputs.

## Planning

- `PLAN_READY`
- `PLAN_UPDATE_REQUIRED`
- `ARCHITECTURE_APPROVED`
- `ARCHITECTURE_APPROVED_WITH_CHANGES`
- `ARCHITECTURE_NOT_APPROVED`
- `IMPACT_ANALYZED`
- `BASELINE_RECORDED`
- `BASELINE_BLOCKED` — the accepted baseline could not be recorded because required
  tests, environment, dependencies, or repository state prevented a trustworthy
  baseline run. Implementation must not begin.

## Implementation

- `READY_TO_IMPLEMENT`
- `READY_FOR_REVIEW`
- `NOT_READY`
- `REVIEW_PASS`
- `REVIEW_FINDINGS`
- `ARCHITECTURE_CONSISTENT`
- `ARCHITECTURE_DRIFT_FOUND`

## Commit and Push

- `READY_FOR_COMMIT`
- `READY_FOR_PRE_PUSH_REVIEW`
- `READY_TO_PUSH`
- `NEEDS_FIXES`
- `NEEDS_MANUAL_DECISION`

## Merge

- `READY_TO_MERGE`
- `NEEDS_REBASE_OR_UPDATE`
- `STILL_BLOCKED`
- `MERGED`
- `LOCAL_MAIN_SYNCED`

## Delivery Status

Use human-readable status alongside the gate:

```text
US1–US4 complete and validated; US5 deferred.
```

Never convert a partial delivery into “feature complete.”
