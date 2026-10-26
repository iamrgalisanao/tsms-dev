# Contract: Intake timestamp rejections

`POST /api/v1/transactions/official`. Mode: `ingestion.timestamps.mode`. In `observe` (default) nothing below is rejected; would-be rejections are logged and counted as `intake.timestamp_would_reject.{code}`. In `enforce` the table applies. Applies to each transaction's `transaction_timestamp` after the existing format check passes.

| Case | Status | error_code |
| --- | --- | --- |
| Zero date part (e.g. `0000-00-00T00:00:00Z`), impossible date (`2026-02-30`), or before `timestamps.earliest` | 422 | `INVALID_TRANSACTION_TIMESTAMP` |
| Later than received time + `future_tolerance_minutes` (normalized per provider timezone mode) | 422 | `FUTURE_TRANSACTION_TIMESTAMP` |
| Older than `late_threshold_minutes` | unchanged (accepted) | none; late flag in metrics and log |
| Current | unchanged | none |

The 422 body has the same shape as the existing structural-validation failure: `success:false`, `message`, `error_code`, `errors{<path>.transaction_timestamp:[...]}`, `correlation_id`. The rejection is persisted to `transaction_intake` with `intake_status=REJECTED` and the code above.
