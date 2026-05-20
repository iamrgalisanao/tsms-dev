# V2.1 Language-Agnostic Payload Checksum Guide

This guide defines how any POS system can generate a TSMS V2.1-compliant payload and checksum, regardless of programming language or JSON library.

Use this as the primary reference for POS providers. Language-specific examples, such as VB.NET, Java, C#, PHP, Python, or JavaScript, should follow these same rules exactly.

## 1. Golden Rule

The checksum must be computed from the exact V2.1 canonical JSON representation of the payload.

It is not enough for the JSON to contain the same values. The canonical JSON used for hashing must match TSMS rules for:

- object key order
- nested object key order
- array order
- money formatting
- whitespace
- escaping
- which `payload_checksum` field is excluded

If any of these differ, the SHA-256 checksum will differ.

## 2. Required Payload Shape

V2.1 Phase 1 accepts one transaction per submission:

```json
{
  "submission_uuid": "NEW_UUID",
  "tenant_id": 16,
  "terminal_id": 97,
  "submission_timestamp": "2026-05-14T08:59:28Z",
  "transaction_count": 1,
  "transaction": {
    "hardware_id": "BUI-XTM80213"
  },
  "payload_checksum": "ROOT_SHA256"
}
```

Rules:

- `transaction_count` must be `1`.
- Use `transaction`, not `transactions`.
- `hardware_id` must be inside `transaction`.
- `submission_uuid` should be new for each new API submission attempt.
- `transaction_id` should be new for a new sale event, but reused when retrying the same sale event.

## 3. Money Formatting

For V2.1, these fields must be strings with exactly two decimal places:

- `gross_sales`
- `net_sales`
- every `amount` inside `adjustments`
- every `amount` inside `taxes`

Correct:

```json
"gross_sales": "140.00"
```

Incorrect:

```json
"gross_sales": 140
```

Also incorrect:

```json
"gross_sales": "140"
```

Always use a dot as the decimal separator, regardless of machine locale.

## 4. Canonicalization Rules

Before hashing:

1. Remove the `payload_checksum` field from the object currently being hashed.
2. Recursively sort all object keys alphabetically using ordinal/ASCII-style comparison.
3. Preserve array item order.
4. Format money fields as two-decimal strings.
5. Encode as compact JSON with no spaces, tabs, or line breaks.
6. Use UTF-8 bytes.
7. Hash using SHA-256.
8. Output lowercase hexadecimal.

Important:

- Sort object keys only.
- Do not sort array items.
- Recursively sort objects inside arrays.
- Do not remove `null` values if they exist in the payload.

## 5. Checksum Order

There are two checksum layers.

### Step 1: Transaction Checksum

Build the transaction object without `transaction.payload_checksum`.

Canonicalize that transaction object.

Hash the canonical transaction JSON:

```text
transaction.payload_checksum = SHA256(canonical_transaction_json_without_payload_checksum)
```

### Step 2: Root Submission Checksum

Add `transaction.payload_checksum` into the transaction object.

Build the root submission object without root `payload_checksum`.

Canonicalize the full root submission object, including the transaction object that already contains `transaction.payload_checksum`.

Hash the canonical root JSON:

```text
payload_checksum = SHA256(canonical_root_json_without_root_payload_checksum)
```

## 6. Pseudocode

```text
FUNCTION formatMoney(value):
    RETURN value formatted as string with exactly 2 decimals using "." separator

FUNCTION canonicalize(value, fieldName = null):
    IF value is object/map:
        sortedObject = empty object/map

        FOR EACH key IN keys(value) sorted alphabetically by ordinal comparison:
            sortedObject[key] = canonicalize(value[key], key)

        RETURN sortedObject

    IF value is array/list:
        canonicalArray = empty array/list

        FOR EACH item IN value in original order:
            canonicalArray.append(canonicalize(item, null))

        RETURN canonicalArray

    IF fieldName is "gross_sales", "net_sales", or "amount":
        IF value is numeric or numeric string:
            RETURN formatMoney(value)

    RETURN value

FUNCTION compactJson(value):
    RETURN JSON encoding with no whitespace using UTF-8-compatible escaping

FUNCTION sha256Hex(value):
    RETURN lowercase SHA-256 hex of UTF-8 bytes(value)

FUNCTION computeChecksum(object):
    copy = deepCopy(object)
    remove copy["payload_checksum"]
    canonical = canonicalize(copy)
    return sha256Hex(compactJson(canonical))

transaction = buildTransactionWithoutChecksum()
transaction["payload_checksum"] = computeChecksum(transaction)

payload = buildRootPayloadWithoutChecksum(transaction)
payload["payload_checksum"] = computeChecksum(payload)
```

## 7. Canonical Key Order For The Sample Payload

Different payloads may have different fields, but this sample shows the expected sorted order.

### Transaction Without `payload_checksum`

```text
adjustments
customer_code
gross_sales
hardware_id
net_sales
promo_status
receipt_no
taxes
transaction_id
transaction_timestamp
```

### Transaction With `payload_checksum`

```text
adjustments
customer_code
gross_sales
hardware_id
net_sales
payload_checksum
promo_status
receipt_no
taxes
transaction_id
transaction_timestamp
```

### Root Without `payload_checksum`

```text
submission_timestamp
submission_uuid
tenant_id
terminal_id
transaction
transaction_count
```

### Root With `payload_checksum`

```text
payload_checksum
submission_timestamp
submission_uuid
tenant_id
terminal_id
transaction
transaction_count
```

### Adjustment Object

```text
adjustment_type
amount
```

### Tax Object

```text
amount
tax_type
```

## 8. Sample Canonical Transaction JSON

This is the canonical transaction JSON before `transaction.payload_checksum` is added:

```json
{"adjustments":[{"adjustment_type":"promo_discount","amount":"0.00"},{"adjustment_type":"senior_discount","amount":"0.00"},{"adjustment_type":"pwd_discount","amount":"0.00"},{"adjustment_type":"vip_card_discount","amount":"0.00"},{"adjustment_type":"service_charge_distributed_to_employees","amount":"0.00"},{"adjustment_type":"service_charge_retained_by_management","amount":"0.00"},{"adjustment_type":"employee_discount","amount":"0.00"}],"customer_code":"C-B1028","gross_sales":"140.00","hardware_id":"BUI-XTM80213","net_sales":"125.00","promo_status":"WITHOUT_APPROVAL","receipt_no":"000001072840","taxes":[{"amount":"15.00","tax_type":"VAT"},{"amount":"125.00","tax_type":"VATABLE_SALES"},{"amount":"0.00","tax_type":"SC_VAT_EXEMPT_SALES"},{"amount":"0.00","tax_type":"OTHER_TAX"}],"transaction_id":"f9c5dd6a-2d71-40b8-903c-df0a02b417ed","transaction_timestamp":"2026-05-14T08:59:28Z"}
```

For this exact canonical string, the transaction checksum is:

```text
afe7b98146c54ba0c80e18d6db0e3976b3204bb046dbd495b03b28152e19ae5f
```

## 9. Sample Final Payload

This final payload validates against TSMS `PayloadChecksumService`:

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
      { "adjustment_type": "promo_discount", "amount": "0.00" },
      { "adjustment_type": "senior_discount", "amount": "0.00" },
      { "adjustment_type": "pwd_discount", "amount": "0.00" },
      { "adjustment_type": "vip_card_discount", "amount": "0.00" },
      { "adjustment_type": "service_charge_distributed_to_employees", "amount": "0.00" },
      { "adjustment_type": "service_charge_retained_by_management", "amount": "0.00" },
      { "adjustment_type": "employee_discount", "amount": "0.00" }
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

Whitespace and display key order in the final submitted JSON do not matter to the server after parsing, but the checksum must have been generated from compact canonical JSON.

## 10. Business Validation Reminder

Checksum validation only proves payload integrity. It does not prove business correctness.

For the sample sale:

```text
gross_sales = 140.00
net_sales = 125.00
VAT = 15.00
VATABLE_SALES = 125.00
```

Avoid sending `VATABLE_SALES = 0.00` for this sale unless the transaction is intentionally modeled as non-vatable or exempt. A payload can pass checksum validation and still fail business reconciliation.

## 11. Common Mistakes

| Mistake | Why It Fails | Fix |
| :--- | :--- | :--- |
| Hashing pretty JSON | Whitespace changes the hash | Hash compact canonical JSON only |
| Hashing fields in insertion order | TSMS sorts object keys before hashing | Sort every object by key |
| Sorting array items | TSMS preserves array order | Sort object keys only |
| Sorting root only | Nested objects also affect the hash | Recursively sort nested objects |
| Hashing root before transaction checksum exists | Root hash must include `transaction.payload_checksum` | Compute transaction checksum first |
| Hashing final payload while root `payload_checksum` is present | The checksum field being computed must be removed | Remove only the current object's checksum field |
| Sending `hardware_id` only at root | V2.1 requires `transaction.hardware_id` | Put `hardware_id` inside transaction before hashing |
| Using locale decimals | Some systems output `140,00` | Use invariant decimal formatting |

## 12. Local Validation

In the TSMS development environment, save the generated payload as `payload.json`, then run:

```bash
php scripts/validate_and_correct_payload.php payload.json
```

Expected result:

```text
Payload is valid.
```

If the validator returns corrected checksums, compare the canonical strings used by the POS against the canonical order in this guide.

## 13. Provider Implementation Checklist

- [ ] Uses one canonicalization function for all payloads.
- [ ] Sorts every object/map recursively.
- [ ] Preserves array item order.
- [ ] Uses compact JSON for hashing.
- [ ] Uses UTF-8 bytes for SHA-256.
- [ ] Outputs lowercase SHA-256 hex.
- [ ] Formats all money values as quoted two-decimal strings.
- [ ] Computes transaction checksum before root checksum.
- [ ] Includes `transaction.payload_checksum` while computing root checksum.
- [ ] Excludes root `payload_checksum` while computing root checksum.
- [ ] Sends `hardware_id` inside `transaction`.
- [ ] Validates sample payload successfully before UAT.
