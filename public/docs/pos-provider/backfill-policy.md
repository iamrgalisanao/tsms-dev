# TSMS POS Provider Backfill And Resend Policy

This policy explains how POS providers should resend transactions or perform historical backfill safely.

## Core Rules

- Send one transaction per submission unless PITX explicitly approves a batch format.
- Keep `transaction_count` aligned with the payload. For current single-transaction payloads, send `transaction_count: 1`.
- Always preserve the original POS `transaction_timestamp`.
- Reuse the same `submission_uuid` only when replaying the exact same submission for idempotency.
- Generate a new `submission_uuid` for each distinct transaction submission.
- Preserve receipt number formatting, including leading zeroes.

## Recommended Backfill Pace

For normal staging/provider validation:

- Send individual submissions at least 1 to 2 seconds apart per terminal.
- Pause and inspect status if repeated 4xx validation errors occur.
- Use `GET /api/v1/submissions/{submission_uuid}` to verify replay outcomes.
- Use the Payload Sandbox before live backfill when payload shape or checksum behavior changed.

Observed safe staging sample:

- 14 individual submissions in about 21 seconds.
- Each submission was a separate API request.
- Each payload used `transaction_count: 1`.
- No Horizon backlog was observed after the resend window.

## Rate Limit Behavior

TSMS applies API rate limiting to authenticated transaction endpoints. If a provider receives rate-limit responses, slow the resend pace and resume from the last unconfirmed `submission_uuid`.

## Failure Handling

| Scenario | Action |
| --- | --- |
| Network timeout or SSL failure | Retry the same payload with the same `submission_uuid`. |
| `Submission already accepted` | Stop retrying that submission and move to the next transaction. |
| 422 validation error | Fix the payload and validate in the sandbox before sending again. |
| 404 on status lookup | Confirm token scope, tenant, terminal, and UUID. |
| Repeated retryable processing failure | Escalate to PITX support with `submission_uuid`, `terminal_id`, and timestamp. |

## What To Avoid

- Do not convert historical sales timestamps to the resend time.
- Do not reuse one `submission_uuid` for multiple different transactions.
- Do not send rapid uncontrolled loops after receiving validation errors.
- Do not use production ingestion tokens for provider support/testing workflows.
