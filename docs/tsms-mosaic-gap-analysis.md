# TSMS And Mosaic POS API Gap Analysis

Date: 2026-05-26

## Purpose

This document compares the current TSMS transaction-ingestion capability with the integration style exposed by the Mosaic staging API documentation at `https://stg-api.mosaicpos.com/docs`.

The goal is to identify practical gaps that affect POS provider onboarding, troubleshooting, monitoring, and operational confidence.

## Source Notes

The Mosaic staging API documentation presents an OpenAPI-style API surface with JSON over HTTPS, OAuth-based authentication, and modules for orders, locations, products, product categories, modifiers, service types, taxes, webhooks, and webhook setup.

TSMS is currently strongest as a financial transaction intake, validation, idempotency, and asynchronous processing system. Mosaic is broader: it behaves more like a full POS integration platform API.

## Executive Summary

TSMS has strong internal transaction-ingestion controls:

- Official transaction submission endpoint.
- Payload checksum validation.
- Submission UUID idempotency.
- Async intake and processing through Horizon queues.
- POS void endpoint.
- Audit and logging behavior.
- Tenant inactivity checks already visible in scheduler logs.

The most important gaps are provider-facing maturity features:

- Public OpenAPI contract.
- Payload Sandbox validation endpoint.
- Submission status lookup by `submission_uuid`.
- Provider-facing rejection diagnostics.
- Tenant and terminal heartbeat dashboard/API.
- Webhook registration and delivery observability.
- Clear resend/backfill policy.

For the immediate PITX provider issues, the highest-value work is not catalog/order expansion. It is improving the provider integration lifecycle: test, submit, inspect, diagnose, and monitor.

## Comparison Matrix

| Area | Mosaic API Pattern | TSMS Current State | Gap / Recommendation |
| --- | --- | --- | --- |
| API documentation | OpenAPI-style documentation with examples | TSMS has Markdown guides but no equivalent provider-facing OpenAPI portal | Publish OpenAPI spec and Postman collection |
| Authentication | OAuth token flow | TSMS uses Bearer/Sanctum terminal tokens | Keep terminal tokens; consider OAuth client credentials for provider-level access |
| Transaction ingestion | Order/lifecycle-oriented API | TSMS has official transaction submission with checksum and async intake | TSMS is strong here; document idempotent replay clearly |
| Idempotency | Not confirmed from visible summary | TSMS returns `Submission already accepted` for duplicate `submission_uuid` | TSMS advantage; expose provider lookup endpoint |
| Payload validation | Standard API request validation | TSMS has a Payload Sandbox at `POST /api/v1/sandbox/payload/validate` and UI at `/sandbox/payload` | Confirm deployment visibility and include it in provider onboarding docs |
| Orders | Create/list/get/cancel order support | TSMS receives completed sales transactions | Decide explicitly whether TSMS will remain reporting-ingestion only |
| Void/cancel | Order cancellation support | TSMS marks an existing transaction as voided and excludes it from selected reports | Document void behavior: status marker, not negative sale computation |
| Product/catalog sync | Products, categories, modifiers | No equivalent provider-facing TSMS catalog API in current flow | Not essential for TSMS reporting ingestion; defer unless business scope expands |
| Tax setup | Tax and location tax endpoints | TSMS receives tax rows inside each transaction | Add tax validation/sandbox rules before any tax setup API |
| Webhooks | Webhook setup and event delivery | TSMS has internal forwarding/callback behavior but no self-service webhook setup API | Add webhook registration, delivery logs, retry visibility |
| Monitoring | Event/webhook-driven integration pattern | TSMS has Horizon and tenant inactivity logs | Add tenant/terminal heartbeat dashboard and API |
| Backfill behavior | Not assessed | TSMS accepts rapid sequential resends if within rate and queue capacity | Publish resend interval and backfill guidance |
| Error observability | Standard API error model expected | TSMS returns errors, but providers need logs or manual support | Add rejection lookup and machine-readable error catalog |

## Current TSMS Observations From Troubleshooting

### Idempotent Replay Works

When the same `submission_uuid` is submitted again, TSMS can return:

```json
{
  "success": true,
  "message": "Submission already accepted",
  "data": {
    "intake_status": "QUEUED",
    "processing_status": "PROCESSED",
    "last_error_code": null
  }
}
```

This is good behavior for POS retry safety. It should be documented as a provider-facing contract.

### Sequential Backfill Was Observed

The reviewed POS logs showed two modes:

- Real-time sends: transactions submitted about 3 to 4 seconds after transaction timestamp.
- Backfill/resend: 14 historical transactions submitted in about 21 seconds, roughly 1 to 2 seconds apart.

Each resend was still a single transaction payload with:

```json
"transaction_count": 1
```

This is not a batch payload. It is sequential replay/backfill using individual API calls.

### Payload Completeness In Reviewed POS File

The reviewed `data/logs.md` payloads were structurally complete:

- `payload_checksum`
- `submission_timestamp`
- `submission_uuid`
- `tenant_id`
- `terminal_id`
- `transaction`
- `transaction_count`
- `transaction.receipt_no`
- `transaction.customer_code`
- `transaction.gross_sales`
- `transaction.net_sales`
- `transaction.adjustments`
- `transaction.taxes`

In those samples, `customer_code` was present. In some observed payloads it may be nullable; the provider contract should clarify whether it is required and whether `null` is acceptable.

### Separate Staging Logs Show Missing Receipt Number

Staging Laravel logs showed some requests failing with:

```text
TSMSTransactionRequest: Validation failed
transaction.receipt_no: The transaction.receipt no field is required.
```

This points to provider payload inconsistency across sends, not a TSMS queue capacity issue.

## Essential Feature Gaps

### 1. Provider-Facing OpenAPI Contract

Problem:

Providers currently depend on Markdown guides, examples, and support interactions. This slows onboarding and makes payload contract drift more likely.

Recommendation:

Publish OpenAPI documentation for:

- `POST /api/v1/transactions/official`
- `GET /api/v1/transactions/{transaction}/status`
- `GET /api/v1/submissions/{submission_uuid}`
- `POST /api/v1/transactions/{transaction}/void`
- `POST /api/v1/transactions/{transaction}/refund`
- `POST /api/v1/transactions/validate`

Success criteria:

- Providers can generate clients or Postman collections.
- All required fields are visible.
- Error responses are documented with examples.
- Checksum expectations are linked from the endpoint docs.

Current implementation:

TSMS now publishes provider-facing artifacts at:

```text
GET /docs/pos-provider/api-testing
GET /docs/pos-provider/openapi.yaml
GET /docs/pos-provider/postman_collection.json
GET /docs/pos-provider/error-catalog.md
GET /docs/pos-provider/backfill-policy.md
```

### 2. Payload Sandbox Validation

Problem:

Providers may still discover payload errors through real submission if they are not directed to the existing sandbox first.

Current capability:

TSMS already has:

```text
POST /api/v1/transactions/validate
POST /api/v1/sandbox/payload/validate
GET  /sandbox/payload
```

The deployed sandbox endpoint performs:

- JSON shape validation.
- Required field validation.
- Tenant-terminal ownership validation.
- Receipt number validation.
- Checksum validation.
- Tax and adjustment structure validation.
- Business reconciliation diagnostics.
- Optional canonical JSON debug output with `include_debug=true`.

It does not submit a transaction to production intake.

Recommendation:

Do not build a second validation endpoint unless there is a contract reason to alias it. Instead:

- Add the sandbox endpoint to provider-facing OpenAPI docs.
- Add it to the Postman collection.
- Confirm staging and production deployment paths.
- Align provider onboarding instructions so payloads are tested in the sandbox before live submission.

Success criteria:

- Invalid payload returns actionable field pointers and error codes.
- Valid payload returns a `valid: true` result.
- Response includes checksum diagnostics when checksums fail.

### 3. Submission Status Lookup

Problem:

Providers need to know whether a submission was accepted, rejected, queued, processed, duplicate, or failed.

Current implementation:

TSMS now includes a read-only provider testing/support endpoint:

```text
GET /api/v1/submissions/{submission_uuid}
```

The route is protected by Sanctum `transaction:read` and `provider:testing` abilities plus API rate limiting. Terminal tokens are scoped to their own `tenant_id` and `terminal_id`; out-of-scope submissions return `404` to avoid cross-tenant or cross-terminal disclosure.

The endpoint does not mutate intake records, dispatch jobs, retry jobs, or touch the POS transaction ingestion pipeline.

Recommendation:

Include this endpoint in provider-facing OpenAPI docs and Postman collections.

```text
GET /api/v1/submissions/{submission_uuid}
```

Suggested response:

```json
{
  "submission_uuid": "uuid",
  "intake_status": "QUEUED",
  "processing_status": "PROCESSED",
  "last_error_code": null,
  "last_error_message": null,
  "received_at": "2026-05-26T05:48:33Z",
  "queued_at": "2026-05-26T05:48:33Z",
  "processed_at": "2026-05-26T05:48:35Z",
  "transaction_id": "uuid",
  "receipt_no": "0000002410"
}
```

Success criteria:

- Providers can self-check replay outcomes.
- Support can reference a single submission UUID.
- Idempotent replay behavior is easier to explain.

### 4. Tenant And Terminal Heartbeat Monitoring

Problem:

Operations need to know whether tenants and terminals are continuously sending transactions daily.

Recommendation:

Add a dashboard/API that reports:

- `tenant_id`
- `tenant_name`
- `transactions_today`
- `transactions_yesterday`
- `last_received_at`
- `last_transaction_timestamp`
- `active_terminals_today`
- `silent_terminals`
- `minutes_since_last_transaction`
- `status`: `active`, `silent`, `no_submission_today`, `inactive_configured`

Suggested endpoints:

```text
GET /api/v1/monitoring/tenants/activity
GET /api/v1/monitoring/terminals/activity
```

Current implementation:

TSMS now includes read-only provider testing/support monitoring endpoints:

```text
GET /api/v1/monitoring/tenants/activity
GET /api/v1/monitoring/terminals/activity
```

Both endpoints require `transaction:read` and `provider:testing` abilities. Results are scoped to the authenticated actor, so terminal tokens can only inspect their own tenant/terminal activity.

Success criteria:

- Helpdesk can identify silent tenants without reading logs.
- Alerts can be sent when expected tenants go silent.
- Inactive tenants can be excluded to reduce false alarms.

### 5. Webhook Registration And Delivery Logs

Problem:

Mosaic-style APIs expose webhook setup as part of the integration lifecycle. TSMS has forwarding and callbacks, but providers do not have self-service webhook observability.

Recommendation:

Add webhook setup and delivery status for events:

- `submission.accepted`
- `submission.rejected`
- `transaction.processed`
- `transaction.voided`
- `transaction.failed`
- `tenant.silent`

Suggested endpoints:

```text
GET /api/v1/webhooks
POST /api/v1/webhooks
PATCH /api/v1/webhooks/{id}
DELETE /api/v1/webhooks/{id}
GET /api/v1/webhook-deliveries
```

Success criteria:

- Providers can manage callback URLs safely.
- Delivery failures are visible.
- Retries and signatures are auditable.

### 6. Backfill And Rate Guidance

Problem:

Backfills are operationally valid, but without explicit guidance providers may send too aggressively or at bad times.

Recommendation:

Document:

- Acceptable resend interval per terminal.
- Recommended backfill batch size.
- Recommended delay between submissions.
- Rate limit behavior.
- How to resume after failed sends.
- Required use of original business transaction timestamp.

Current observed safe sample:

- 14 individual submissions in about 21 seconds.
- About 1 to 2 seconds between sends.
- No Horizon backlog observed in the current screenshot.

Success criteria:

- Providers know how to retry safely.
- TSMS operations can distinguish live sending from historical backfill.

Current implementation:

Provider-facing backfill guidance is now available at:

```text
GET /docs/pos-provider/backfill-policy.md
```

## Prioritized Roadmap

### Phase 1: Provider Diagnostics And Contract Clarity

1. Publish OpenAPI spec for existing transaction endpoints. `Done`
2. Add validation-only endpoint. `Done`
3. Add submission status lookup by `submission_uuid`. `Done`
4. Add machine-readable error codes and field pointers. `Done`
5. Publish Postman collection. `Done`

Rationale:

These features directly address the current provider troubleshooting cycle.

### Phase 2: Operational Monitoring

1. Build tenant activity dashboard/API. `Done`
2. Build terminal activity dashboard/API. `Done`
3. Add configurable inactivity thresholds. `Done`
4. Add daily heartbeat report. `Done`
5. Add alert suppression/cooldown visibility. `Done`

Rationale:

These features answer whether providers are continuously sending transactions and reduce silent-failure risk.

### Phase 3: Eventing And Provider Observability

1. Add webhook registration.
2. Add webhook delivery logs.
3. Add signed webhook payloads.
4. Add retry/replay for failed webhook deliveries.

Rationale:

This improves integration maturity but is less urgent than validation and status lookup.

### Phase 4: Broader POS Platform Scope

1. Evaluate order lifecycle support.
2. Evaluate catalog sync.
3. Evaluate tax configuration/reference APIs.

Rationale:

These are Mosaic-style platform features. They should only be implemented if TSMS scope expands beyond reporting and transaction compliance ingestion.

## Recommended Immediate Plan

The most essential remaining features to implement first are:

1. Email/notification alerts for silent tenants/terminals `Done`
2. OpenAPI viewer UI embedded in the docs page
3. Webhook registration and delivery logs

These provide the fastest operational value with the least scope expansion.
