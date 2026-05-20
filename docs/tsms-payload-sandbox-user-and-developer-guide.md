# TSMS Payload Sandbox User And Developer Guide

This guide explains how POS providers can use the TSMS Payload Sandbox to test V2.1 payloads before sending data to production ingestion.

The guide is written for two audiences:

- Non-technical users who need to know what the sandbox is, what to paste, and how to read the result.
- Technical developers who need endpoint details, response fields, checksum rules, diagnostics, and security expectations.

## 1. What The Sandbox Does

The Payload Sandbox validates one TSMS V2.1 POS payload and returns detailed feedback.

It checks:

- JSON format
- required fields
- V2.1 payload shape
- transaction checksum
- root submission checksum
- `hardware_id` location
- tenant and terminal mapping, when available
- money field formatting
- VAT and sales amount reconciliation

The sandbox does not submit a transaction to production. It is a diagnostic tool only.

Public UI:

```text
/sandbox/payload
```

Public validation API:

```text
POST /api/v1/sandbox/payload/validate
```

## 2. When To Use It

Use the sandbox when:

- a POS provider is building the TSMS integration
- a payload fails checksum validation
- a provider wants to confirm the correct V2.1 payload shape
- a developer wants to compare provided checksums against TSMS-computed checksums
- QA wants to test valid and invalid sample payloads
- support needs a clear validation report before escalating an issue

Do not use the sandbox as a production transaction submission endpoint.

## 3. Guide For Non-Technical Users

### Step 1: Open The Sandbox

Open:

```text
/sandbox/payload
```

The page is publicly available for POS providers.

### Step 2: Paste A Payload

The large Request Payload box shows a sample payload as placeholder text.

Important:

- The placeholder is only an example.
- Click inside the box and paste the actual JSON payload from the POS.
- If the box is empty and you click Validate, nothing will be submitted.
- The sample buttons can load test payloads when needed.

Buttons:

- `Valid`: loads a known valid V2.1 sample.
- `Invalid`: loads a sample with common mistakes.
- `Clear`: clears the editor.
- `Validate`: submits the pasted payload for validation.

### Step 3: Choose Debug Output

The `Canonical debug` switch controls whether the response includes the exact canonical JSON used for checksum hashing.

For business or QA users:

- Leave it on when helping developers troubleshoot.
- Turn it off if you only need the pass/fail summary.

For developers:

- Leave it on when comparing your local checksum generation with TSMS.

### Step 4: Read The Validation Summary

The Validation Summary shows four checks:

| Check | Meaning |
| :--- | :--- |
| `schema` | The JSON has required fields and correct basic data types. |
| `checksum` | Transaction and root checksums match TSMS canonical hashing. |
| `contract` | V2.1-specific rules are followed. |
| `business_rules` | Sales, tax, and amount relationships look correct. |

If all checks pass, the payload is valid for sandbox rules.

If one or more checks fail, review the Diagnostics section.

### Step 5: Read Checksum Comparison

Checksum Comparison shows:

- `transaction` checksum
- `submission` checksum

Each checksum has:

- `Provided`: the checksum sent by the POS.
- `Computed`: the checksum computed by TSMS.

If they match, that checksum is correct.

If they mismatch, the POS should not blindly copy the computed checksum. The POS must fix its checksum generation logic so future payloads are generated correctly.

### Step 6: Read Diagnostics

Diagnostics shows specific issues, such as:

- missing fields
- invalid amount formatting
- wrong checksum
- `hardware_id` in the wrong place
- VAT and net sales mismatch

Each diagnostic includes:

- a code
- a readable message
- a JSON pointer showing where the problem is
- expected and actual values when useful

Example pointer:

```text
/transaction/taxes/1/amount
```

This means the issue is inside:

- `transaction`
- then `taxes`
- then the second tax row
- then the `amount` field

Array indexes start at zero.

## 4. Guide For Technical Developers

### Endpoint

```http
POST /api/v1/sandbox/payload/validate
Content-Type: application/json
Accept: application/json
```

Optional query parameter:

```text
include_debug=true
```

Example:

```http
POST /api/v1/sandbox/payload/validate?include_debug=true
Content-Type: application/json
Accept: application/json
```

### Request Body

Send the raw V2.1 payload JSON object.

Example:

```json
{
  "submission_uuid": "f8c1a35a-90db-41ab-8c05-1b27cc7a40a9",
  "tenant_id": 16,
  "terminal_id": 97,
  "submission_timestamp": "2026-05-14T08:59:28Z",
  "transaction_count": 1,
  "transaction": {
    "hardware_id": "BUI-XTM80213",
    "receipt_no": "000001072840",
    "transaction_id": "f9c5dd6a-2d71-40b8-903c-df0a02b417ed",
    "transaction_timestamp": "2026-05-14T08:59:28Z",
    "gross_sales": "140.00",
    "net_sales": "125.00",
    "promo_status": "WITHOUT_APPROVAL",
    "customer_code": "C-B1028",
    "adjustments": [
      { "adjustment_type": "promo_discount", "amount": "0.00" }
    ],
    "taxes": [
      { "tax_type": "VAT", "amount": "15.00" },
      { "tax_type": "VATABLE_SALES", "amount": "125.00" },
      { "tax_type": "SC_VAT_EXEMPT_SALES", "amount": "0.00" },
      { "tax_type": "OTHER_TAX", "amount": "0.00" }
    ],
    "payload_checksum": "afe7b98146c54ba0c80e18d6db0e3976b3204bb046dbd495b03b28152e19ae5f"
  },
  "payload_checksum": "30cf6eeb6513ca6f31e9d091e7bbd8a519f5d3264a128cc715fee6d61b684154"
}
```

### Example cURL

```bash
curl -X POST "https://YOUR-TSMS-HOST/api/v1/sandbox/payload/validate?include_debug=true" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  --data-binary @payload.json
```

Use `--data-binary` so the file is sent as-is.

### Response Shape

Successful validation request:

```json
{
  "success": true,
  "valid": true,
  "validation_id": "val_01J...",
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
  "errors": [],
  "warnings": [],
  "checksums": {
    "transaction": {
      "provided": "...",
      "computed": "...",
      "matches": true,
      "safe_to_copy": false
    },
    "submission": {
      "provided": "...",
      "computed": "...",
      "matches": true,
      "safe_to_copy": false
    }
  }
}
```

Failed validation request:

```json
{
  "success": true,
  "valid": false,
  "summary": {
    "error_count": 3,
    "warning_count": 1
  },
  "checks": {
    "schema": "passed",
    "checksum": "failed",
    "contract": "failed",
    "business_rules": "failed"
  },
  "errors": [
    {
      "code": "TRANSACTION_CHECKSUM_MISMATCH",
      "severity": "error",
      "pointer": "/transaction/payload_checksum",
      "message": "Transaction checksum does not match the V2.1 canonical transaction object.",
      "expected": "computed checksum",
      "actual": "provided checksum",
      "hint": "Remove only transaction.payload_checksum, canonicalize the remaining transaction object, then SHA-256 hash the compact JSON."
    }
  ],
  "warnings": []
}
```

Important:

- `success` means the sandbox request was processed.
- `valid` means the payload itself passed validation.
- A response can have `success: true` and `valid: false`.

## 5. HTTP Status Codes

| Status | Meaning |
| :--- | :--- |
| `200` | JSON was parsed and payload diagnostics were returned. The payload may still be invalid. |
| `400` | Request body is not a valid JSON object. |
| `415` | `Content-Type` is not `application/json`. |
| `429` | Too many requests. Try again later. |
| `500` | Unexpected server error. Report the `validation_id` or request time to TSMS support. |

## 6. Required V2.1 Payload Rules

Root object:

| Field | Required | Type | Notes |
| :--- | :--- | :--- | :--- |
| `submission_uuid` | Yes | string | New UUID per API submission attempt. |
| `tenant_id` | Yes | integer | Tenant identifier. |
| `terminal_id` | Yes | integer | POS terminal identifier. |
| `submission_timestamp` | Yes | string | ISO-8601 timestamp. |
| `transaction_count` | Yes | integer | Must be `1` for V2.1 Phase 1. |
| `transaction` | Yes | object | Use `transaction`, not `transactions`. |
| `payload_checksum` | Yes | string | 64-character lowercase SHA-256 hex. |

Transaction object:

| Field | Required | Type | Notes |
| :--- | :--- | :--- | :--- |
| `hardware_id` | Yes | string | Must be inside `transaction`. |
| `receipt_no` | Yes | string | Receipt number. |
| `transaction_id` | Yes | string | New for a new sale event; reused for retry of same sale. |
| `transaction_timestamp` | Yes | string | ISO-8601 timestamp. |
| `gross_sales` | Yes | string | Money string with exactly two decimals. |
| `net_sales` | Yes | string | Money string with exactly two decimals. |
| `promo_status` | Yes | string | `NONE`, `WITH_APPROVAL`, or `WITHOUT_APPROVAL`. |
| `customer_code` | Yes | string | Customer reference. |
| `adjustments` | Yes | array | Array of adjustment objects. |
| `taxes` | Yes | array | Array of tax objects. |
| `payload_checksum` | Yes | string | Transaction checksum. |

Money fields must be strings:

```json
"gross_sales": "140.00"
```

Do not send money as numbers:

```json
"gross_sales": 140
```

Do not omit trailing decimals:

```json
"gross_sales": "140"
```

Always use `.` as the decimal separator.

## 7. Checksum Rules

The sandbox uses the system `PayloadChecksumService`.

Checksum generation must follow these rules:

1. Remove the `payload_checksum` field from the object currently being hashed.
2. Recursively sort all object keys alphabetically.
3. Preserve array item order.
4. Format money fields as strings with exactly two decimals.
5. Encode compact JSON with no spaces, tabs, or line breaks.
6. Hash the UTF-8 bytes using SHA-256.
7. Output lowercase hexadecimal.

There are two checksum layers.

### Transaction Checksum

Compute first:

```text
transaction.payload_checksum = SHA256(canonical transaction JSON without transaction.payload_checksum)
```

### Root Submission Checksum

Compute second:

```text
payload_checksum = SHA256(canonical root JSON without root payload_checksum)
```

The root checksum must include `transaction.payload_checksum`.

This is why the root checksum often fails when the transaction checksum is wrong.

## 8. Canonical Debug Output

When `include_debug=true`, the response includes:

```json
{
  "debug": {
    "canonical_transaction": {},
    "canonical_transaction_json": "...",
    "canonical_submission": {},
    "canonical_submission_json": "..."
  }
}
```

Use this when your local checksum differs from TSMS.

Compare:

- your canonical transaction JSON
- sandbox `canonical_transaction_json`
- your transaction checksum
- sandbox computed transaction checksum
- your canonical root JSON
- sandbox `canonical_submission_json`
- your root checksum
- sandbox computed root checksum

The canonical JSON strings must match byte-for-byte before the checksum can match.

## 9. Diagnostic Codes

| Code | Meaning | Typical Fix |
| :--- | :--- | :--- |
| `UNSUPPORTED_CONTENT_TYPE` | Request is not sent as JSON. | Send `Content-Type: application/json`. |
| `INVALID_JSON` | Body is not valid JSON object. | Fix JSON syntax and submit an object. |
| `MISSING_REQUIRED_FIELD` | A required field is missing. | Add the field shown in `pointer`. |
| `INVALID_FIELD_TYPE` | Field has the wrong JSON type. | Send the expected type. |
| `INVALID_AMOUNT_FORMAT` | Money is not a two-decimal string. | Send values like `"125.00"`. |
| `INVALID_CHECKSUM_FORMAT` | Checksum is not lowercase 64-character SHA-256 hex. | Generate lowercase SHA-256 hex. |
| `BATCH_NOT_SUPPORTED` | Payload uses batch format or count is not `1`. | Use one `transaction` object for V2.1 Phase 1. |
| `HARDWARE_ID_MISSING_IN_TRANSACTION` | `hardware_id` is not inside `transaction`. | Move `hardware_id` into `transaction`. |
| `HARDWARE_ID_AT_ROOT_ONLY` | `hardware_id` was found at root only. | Remove root-only usage and include it in `transaction`. |
| `TENANT_TERMINAL_MISMATCH` | Terminal does not belong to tenant. | Use the correct `tenant_id` and `terminal_id` pair. |
| `INVALID_ENUM_VALUE` | Enum field has unsupported value. | Use accepted values shown in `expected`. |
| `TRANSACTION_CHECKSUM_MISMATCH` | Transaction checksum is wrong. | Fix transaction canonicalization and hash generation. |
| `SUBMISSION_CHECKSUM_MISMATCH` | Root checksum is wrong. | Compute transaction checksum first, then root checksum. |
| `CHECKSUM_CASCADE` | Root mismatch is likely caused by transaction mismatch. | Fix transaction checksum before root checksum. |
| `VAT_RECONCILIATION_FAILED` | VAT exists but VATABLE_SALES is zero. | Send correct VATABLE_SALES for vatable sale. |
| `AMOUNT_RECONCILIATION_FAILED` | `net_sales` does not reconcile with tax buckets. | Align `net_sales`, `VATABLE_SALES`, and exempt sales. |
| `GROSS_RECONCILIATION_WARNING` | Gross formula has a difference. | Review gross, net, VAT, adjustments, and OTHER_TAX. |

## 10. Common Troubleshooting Scenarios

### Transaction checksum fails

Check:

- Did you remove `transaction.payload_checksum` before hashing?
- Did you sort every object key recursively?
- Did you preserve array order?
- Did you format all money fields as `"0.00"` strings?
- Did you hash compact JSON, not pretty JSON?
- Did you use UTF-8 bytes?
- Did you output lowercase hex?

### Root checksum fails

Check:

- Did you compute `transaction.payload_checksum` first?
- Did you include `transaction.payload_checksum` while computing the root checksum?
- Did you remove only the root `payload_checksum` before hashing the root object?

### Sandbox says `hardware_id` is missing

For V2.1, this is correct:

```json
{
  "transaction": {
    "hardware_id": "BUI-XTM80213"
  }
}
```

This is not enough:

```json
{
  "hardware_id": "BUI-XTM80213",
  "transaction": {}
}
```

### Amount format fails

Correct:

```json
"amount": "15.00"
```

Incorrect:

```json
"amount": 15
```

Incorrect:

```json
"amount": "15"
```

### VAT reconciliation fails

For a sale with:

```text
gross_sales = 140.00
net_sales = 125.00
VAT = 15.00
```

The expected `VATABLE_SALES` is:

```text
125.00
```

Do not send `VATABLE_SALES = 0.00` unless the transaction is intentionally non-vatable or exempt.

## 11. Security And Data Handling

The sandbox is public, but it is diagnostic only.

Current safety expectations:

- The UI treats pasted payload as plain text.
- The UI does not execute payload content.
- The UI does not render payload content as HTML.
- The UI does not use `eval`.
- The UI does not use `dangerouslySetInnerHTML`.
- The validation endpoint expects `application/json`.
- Invalid JSON is rejected before validation.
- Payloads are parsed as JSON data, not SQL.
- The validator does not build SQL from payload values.
- Tenant-terminal lookup uses model lookup, not raw SQL string concatenation.
- The public endpoint is rate-limited.
- The UI applies a client-side 256 KB size guard.

Provider guidance:

- Do not paste real customer-sensitive production data unless required for troubleshooting.
- Prefer masked customer codes during testing.
- Do not include passwords, API tokens, credit card numbers, or unrelated personal information in the payload.
- Share `validation_id` with TSMS support instead of screenshots containing sensitive data.

Developer guidance:

- Treat all sandbox input as untrusted.
- Do not add raw SQL queries using payload fields.
- Do not log full payload bodies in production.
- Do not expose stack traces in sandbox responses.
- Keep diagnostics specific enough to fix the issue but avoid revealing secrets.
- Keep the sandbox aligned with `PayloadChecksumService`.

## 12. Provider Acceptance Checklist

Before UAT, the POS provider should confirm:

- [ ] The sandbox valid sample passes.
- [ ] The POS-generated payload passes.
- [ ] `schema` check is `passed`.
- [ ] `checksum` check is `passed`.
- [ ] `contract` check is `passed`.
- [ ] `business_rules` check is `passed`.
- [ ] Transaction checksum matches.
- [ ] Root submission checksum matches.
- [ ] `hardware_id` is inside `transaction`.
- [ ] Money values are strings with exactly two decimals.
- [ ] `transaction_count` is `1`.
- [ ] `transaction`, not `transactions`, is used.
- [ ] Canonical debug output matches the POS canonical JSON during checksum troubleshooting.

## 13. Support Workflow

When reporting a sandbox issue to TSMS support, include:

- sandbox URL used
- date and time of test
- `validation_id`
- whether `include_debug` was enabled
- failing diagnostic codes
- sanitized payload if needed
- POS technology stack and JSON library
- local checksum generation method

Do not send production credentials, access tokens, or customer-sensitive data through email or chat.

