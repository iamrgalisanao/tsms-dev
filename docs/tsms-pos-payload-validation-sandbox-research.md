# TSMS POS Payload Validation Sandbox Research

## Objective

This document summarizes recommended design for an API testing sandbox where POS providers can submit TSMS V2.1 payloads and receive comprehensive, developer-friendly feedback.

The sandbox must comply with the system's existing `PayloadChecksumService` and should help providers fix integration issues before they send payloads to the production ingestion endpoint.

## Current Local Baseline

The codebase already has a lightweight sandbox route:

```text
POST /api/v1/checksum/sandbox
```

Current behavior:

- Requires Sanctum authentication.
- Is rate-limited.
- Accepts a generic `payload` object.
- Computes a checksum using `PayloadChecksumService`.
- Optionally compares a supplied `provided_checksum`.
- Returns `canonical_payload`, `computed_checksum`, and `matches`.

Current limitation:

This endpoint is useful for computing a checksum for one object, but it is not yet a complete POS payload validation sandbox. It does not diagnose the whole submission lifecycle, schema problems, transaction vs root checksum layering, tenant-terminal mismatch, tax bucket reconciliation, or common V2.1 canonicalization mistakes in a structured way.

## Industry Patterns Relevant To This Sandbox

### 1. Machine-Readable Error Responses

RFC 9457, "Problem Details for HTTP APIs", defines a standard `application/problem+json` response format with fields such as `type`, `title`, `status`, `detail`, and `instance`. It also gives a validation-error example where each error includes a JSON Pointer to the failing field.

Recommended TSMS use:

- Use stable machine-readable error codes.
- Include human-readable messages.
- Include field pointers such as `/transaction/taxes/1/amount`.
- Include a trace or validation ID for support.

Reference: https://www.rfc-editor.org/rfc/rfc9457.html

### 2. Field-Level Pointers

RFC 6901 JSON Pointer defines a standard way to point to a location inside a JSON document.

Recommended TSMS use:

- Use JSON Pointers in validation feedback.
- Prefer `/transaction/payload_checksum` over vague messages such as "checksum invalid".
- For array rows, use indexes: `/transaction/taxes/1/tax_type`.

Reference: https://www.rfc-editor.org/rfc/rfc6901

### 3. Stripe-Style Developer Feedback

Stripe's API error model is useful for provider-facing integrations because it combines HTTP status codes, machine-readable error codes, human-readable messages, parameter references, documentation links, and request IDs.

Recommended TSMS use:

- Return `code`, `message`, `pointer`, `expected`, `actual`, and `doc_url`.
- Include `request_id` or `trace_id`.
- Separate programmatic error codes from human-facing explanation.

Reference: https://docs.stripe.com/api/errors

### 4. API Security Guardrails

OWASP API Security Top 10 2023 highlights risks relevant to a public/provider-facing sandbox:

- Broken authentication and authorization.
- Unrestricted resource consumption.
- Security misconfiguration.
- Error messages exposing sensitive information.
- Unsafe consumption of API inputs.

Recommended TSMS use:

- Keep authentication and tenant scoping.
- Rate-limit and size-limit requests.
- Never return stack traces.
- Avoid logging full customer-sensitive payloads.
- Make sandbox diagnostic output useful but not production-secret-bearing.

References:

- https://owasp.org/API-Security/editions/2023/en/0x11-t10/
- https://owasp.org/API-Security/editions/2023/en/0xa8-security-misconfiguration/

### 5. Mock/Sandbox API Lifecycle

API testing tools such as Postman mock servers show the value of provider-accessible test environments that simulate API behavior early, document expected responses, and let integrators debug without touching production.

Recommended TSMS use:

- Provide a stable sandbox endpoint, examples, and Postman collection.
- Return deterministic diagnostics.
- Keep sandbox behavior aligned with production validation code.

Reference: https://www.postman.com/features/mock-api/

## Recommended Sandbox Scope

Build the sandbox as a validation and diagnostics endpoint, not just a checksum calculator.

Recommended endpoint:

```text
POST /api/v1/sandbox/payload/validate
```

Optional companion endpoints:

```text
POST /api/v1/sandbox/checksum/transaction
POST /api/v1/sandbox/checksum/submission
GET  /api/v1/sandbox/validation-rules
GET  /api/v1/sandbox/examples/v2.1
```

## Recommended Validation Pipeline

The sandbox should mirror production validation order, but without inserting business transactions.

### Layer 0: Request Envelope

Checks:

- Valid JSON.
- Content-Type is `application/json`.
- Request body size within limit.
- Authenticated terminal/provider.
- Request has trace ID.

Output examples:

- `INVALID_JSON`
- `UNSUPPORTED_CONTENT_TYPE`
- `PAYLOAD_TOO_LARGE`
- `UNAUTHORIZED`

### Layer 1: Structural Schema

Checks:

- Required root fields exist.
- `transaction_count = 1`.
- Uses `transaction`, not `transactions`.
- Required transaction fields exist.
- `adjustments` and `taxes` are arrays.
- Checksum fields are 64-character lowercase hex strings.
- Money fields are strings with exactly two decimals.
- Timestamps are parseable ISO-8601.

Output examples:

- `MISSING_REQUIRED_FIELD`
- `INVALID_FIELD_TYPE`
- `INVALID_AMOUNT_FORMAT`
- `BATCH_NOT_SUPPORTED`
- `INVALID_CHECKSUM_FORMAT`

### Layer 2: Checksum Diagnostics

This must use `PayloadChecksumService`.

Checks:

- Transaction checksum computed from transaction object with `transaction.payload_checksum` removed.
- Root checksum computed from full root object with root `payload_checksum` removed, while retaining the transaction checksum.
- V2.1 first, V2.0 fallback only as compatibility signal.

Output examples:

- `TRANSACTION_CHECKSUM_MISMATCH`
- `SUBMISSION_CHECKSUM_MISMATCH`
- `CHECKSUM_VALID_VIA_V2_0_FALLBACK`

Diagnostic details:

- Provided transaction checksum.
- Computed transaction checksum.
- Provided root checksum.
- Computed root checksum.
- Canonical transaction JSON or canonical transaction object.
- Canonical root JSON or canonical root object.
- Common likely causes.

Important:

Returning canonical JSON is extremely useful in sandbox, but should be authenticated, rate-limited, and possibly truncated for very large payloads.

### Layer 3: Contract Rules

Checks:

- `hardware_id` is inside `transaction`.
- `tenant_id` matches authenticated terminal mapping.
- `promo_status` is in accepted enum.
- `adjustment_type` and `tax_type` values are accepted.
- Single-transaction Phase 1 rules are followed.

Output examples:

- `HARDWARE_ID_MISSING_IN_TRANSACTION`
- `TENANT_TERMINAL_MISMATCH`
- `INVALID_ENUM_VALUE`

### Layer 4: Business Reconciliation

Checks:

- VAT and VATABLE_SALES reconcile with `net_sales`.
- Gross/net/adjustments/other tax reconcile.
- Negative amounts are rejected unless explicitly allowed.
- Tenant-specific amount ceilings can be simulated if available.

Output examples:

- `AMOUNT_RECONCILIATION_FAILED`
- `VAT_RECONCILIATION_FAILED`
- `INVALID_NEGATIVE_AMOUNT`

## Recommended Response Model

Use a successful HTTP status for a diagnostic report if the sandbox request itself was processed, even when the submitted payload is invalid.

Recommended:

- `200 OK` when the sandbox successfully evaluated the payload.
- `400 Bad Request` for invalid sandbox wrapper request.
- `401/403` for auth failures.
- `413` for payload too large.
- `429` for rate limit.
- `500` only for sandbox server failures.

Example valid response:

```json
{
  "success": true,
  "valid": true,
  "validation_id": "val_01HX...",
  "version": "v2.1",
  "summary": {
    "error_count": 0,
    "warning_count": 0
  },
  "checks": {
    "schema": "passed",
    "checksum": "passed",
    "contract": "passed",
    "business_rules": "passed"
  },
  "checksums": {
    "transaction": {
      "provided": "afe7b98146c54ba0c80e18d6db0e3976b3204bb046dbd495b03b28152e19ae5f",
      "computed": "afe7b98146c54ba0c80e18d6db0e3976b3204bb046dbd495b03b28152e19ae5f",
      "matches": true
    },
    "submission": {
      "provided": "30cf6eeb6513ca6f31e9d091e7bbd8a519f5d3264a128cc715fee6d61b684154",
      "computed": "30cf6eeb6513ca6f31e9d091e7bbd8a519f5d3264a128cc715fee6d61b684154",
      "matches": true
    }
  }
}
```

Example invalid response:

```json
{
  "success": true,
  "valid": false,
  "validation_id": "val_01HX...",
  "version": "v2.1",
  "summary": {
    "error_count": 3,
    "warning_count": 1
  },
  "errors": [
    {
      "code": "HARDWARE_ID_MISSING_IN_TRANSACTION",
      "severity": "error",
      "pointer": "/transaction/hardware_id",
      "message": "hardware_id is required inside transaction.",
      "expected": "A non-empty transaction.hardware_id string.",
      "actual": null,
      "doc_url": "/docs/v2-1-language-agnostic-payload-checksum-guide.md#2-required-payload-shape"
    },
    {
      "code": "TRANSACTION_CHECKSUM_MISMATCH",
      "severity": "error",
      "pointer": "/transaction/payload_checksum",
      "message": "Transaction checksum does not match the V2.1 canonical transaction object.",
      "expected": "2199a619026a1a7137fc17fec2a31f6e6a954368c6593c798a0ed4b120010733",
      "actual": "e0736715ef3e6d86aa176c455908df6b0b1d95169aa8b1a944c9d26d569804da",
      "hint": "Recompute transaction.payload_checksum after finalizing all transaction fields. Remove only transaction.payload_checksum before hashing."
    },
    {
      "code": "VAT_RECONCILIATION_FAILED",
      "severity": "error",
      "pointer": "/transaction/taxes",
      "message": "VAT is 15.00 but VATABLE_SALES is 0.00 for a net sale of 125.00.",
      "expected": {
        "VATABLE_SALES": "125.00"
      },
      "actual": {
        "VATABLE_SALES": "0.00"
      }
    }
  ],
  "warnings": [
    {
      "code": "CHECKSUM_CASCADE",
      "severity": "warning",
      "pointer": "/payload_checksum",
      "message": "Root checksum will fail whenever transaction.payload_checksum is wrong because the root checksum includes the transaction checksum."
    }
  ],
  "checksums": {
    "transaction": {
      "provided": "e0736715ef3e6d86aa176c455908df6b0b1d95169aa8b1a944c9d26d569804da",
      "computed": "2199a619026a1a7137fc17fec2a31f6e6a954368c6593c798a0ed4b120010733",
      "matches": false
    },
    "submission": {
      "provided": "c7ec5ef62a44dca24638944f9dc7402da0d3dde697d510a3c04f4e7a1560c475",
      "computed": "7c17217b4d3350044349cb460bd50f99f5b632170ac08fdaf02f37ac78c11f00",
      "matches": false
    }
  }
}
```

## Recommended Diagnostic Features

### 1. Corrected Checksum Suggestions

Return computed checksums, but make it explicit that the provider should fix the canonicalization logic, not blindly paste the computed value.

Recommended field:

```json
{
  "computed": "abc...",
  "matches": false,
  "safe_to_copy": false,
  "reason": "Checksum mismatch usually means your local canonical string differs from TSMS."
}
```

### 2. Canonical Payload Viewer

Offer optional debug output:

```json
{
  "debug": {
    "canonical_transaction_json": "...",
    "canonical_submission_json": "..."
  }
}
```

Gate this behind:

- Auth.
- Rate limit.
- Request flag such as `include_debug=true`.
- Size limits.
- Redaction rules.

### 3. Provider-Friendly Hints

For each common failure, include likely causes:

- `NESTED_KEYS_NOT_SORTED`
- `ROOT_HASH_COMPUTED_BEFORE_TRANSACTION_HASH`
- `HASHED_PRETTY_JSON`
- `HARDWARE_ID_AT_ROOT_ONLY`
- `AMOUNT_SENT_AS_NUMBER`
- `VATABLE_SALES_ZERO_WITH_VAT`

### 4. Version Feedback

Since `PayloadChecksumService` attempts V2.1 first and V2.0 fallback second, the sandbox should expose:

```json
{
  "checksum_version": {
    "attempted": ["v2.1", "v2.0"],
    "matched": "v2.1"
  }
}
```

If it only passes V2.0 fallback, return a warning:

```json
{
  "code": "CHECKSUM_VALID_VIA_V2_0_FALLBACK",
  "severity": "warning",
  "message": "Payload checksum passed legacy V2.0 normalization, but providers should migrate to V2.1 strict two-decimal string formatting."
}
```

Note: the current `PayloadChecksumService` does not return which version matched. To support this cleanly, add a diagnostic method rather than parsing log output.

## Implementation Recommendations

### 1. Do Not Fork Checksum Logic

The sandbox must call `PayloadChecksumService`. Do not reimplement checksum rules in the controller.

Recommended service:

```text
PayloadSandboxValidationService
```

Responsibilities:

- Parse and validate JSON.
- Run schema checks.
- Call `PayloadChecksumService`.
- Run contract checks.
- Run business reconciliation checks.
- Build diagnostic response.

### 2. Add Diagnostic Methods To PayloadChecksumService

Current methods return only `valid` and string errors.

Recommended additions:

```php
diagnoseSubmission(array $submission): array
computeCanonicalJson($payload, string $version = 'v2.1'): string
computeTransactionChecksumDiagnostics(array $transaction): array
computeSubmissionChecksumDiagnostics(array $submission): array
```

These should return structured values:

- provided checksum
- computed checksum
- matches
- canonical object
- canonical JSON
- version attempted
- version matched

### 3. Keep Production Ingestion Separate

The sandbox should not:

- insert transactions
- create financial records
- forward to downstream systems
- trigger callbacks
- mutate production data

It may store a lightweight validation event for audit and support:

```text
payload_validation_events
```

Suggested fields:

- `validation_id`
- `provider_id`
- `tenant_id`
- `terminal_id`
- `submission_uuid`
- `result`
- `error_codes`
- `payload_fingerprint`
- `created_at`

Avoid storing raw payloads long-term unless there is a clear retention policy and redaction.

### 4. Publish OpenAPI And Postman Collection

Provide:

- OpenAPI spec.
- Postman collection.
- sample valid payloads.
- sample invalid payloads.
- expected diagnostic responses.

This supports multiple POS tech stacks and lets providers automate validation in CI before UAT.

## Security And Privacy Controls

Recommended controls:

- Authenticated provider tokens only.
- Tenant/terminal authorization checks.
- Per-token and per-IP rate limits.
- Payload size limits.
- No stack traces in responses.
- No full raw payload logs by default.
- Redact or hash sensitive fields such as customer identifiers where possible.
- Return trace IDs for support.
- Separate sandbox credentials from production credentials.
- Add monitoring for abuse patterns.

## Phased Delivery Plan

### Phase 1: Enhanced Checksum Sandbox

- Keep existing route or add `/api/v1/sandbox/payload/validate`.
- Accept full POS payload.
- Validate transaction and root checksum.
- Return structured checksum diagnostics.
- Return canonical JSON when requested.

### Phase 2: Full Contract Validation

- Add required field checks.
- Add enum checks.
- Add money format checks.
- Add `hardware_id` placement checks.
- Add JSON Pointer field locations.

### Phase 3: Business Rule Simulation

- Add VAT/gross/net reconciliation.
- Add tenant-terminal matching.
- Add idempotency simulation warnings.

### Phase 4: Provider Portal And CI Tooling

- Add web UI for payload paste/upload.
- Add downloadable diagnostics.
- Add Postman collection.
- Add CLI/script examples.
- Add provider validation history.

## Acceptance Criteria

- The sandbox uses `PayloadChecksumService` for all checksum results.
- The known valid V2.1 sample payload returns `valid: true`.
- The original invalid payload returns actionable errors for checksum mismatch, missing transaction `hardware_id`, and tax reconciliation.
- Each error includes `code`, `message`, `severity`, and `pointer`.
- Sandbox responses include `validation_id`.
- No stack traces or internal paths are returned.
- Requests are authenticated and rate-limited.
- Payloads are not persisted as raw JSON unless explicitly configured with retention and redaction.
