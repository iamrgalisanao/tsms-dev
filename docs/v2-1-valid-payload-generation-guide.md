# V2.1 Valid Payload Generation Guide

This guide explains how to build a TSMS V2.1 payload that passes checksum validation. It is based on the same process used to correct and regenerate a valid payload during development.

## 1. Start From The Sale Details

Identify the sale data that must remain stable:

```json
{
  "tenant_id": 16,
  "terminal_id": 97,
  "hardware_id": "BUI-XTM80213",
  "receipt_no": "000001072840",
  "transaction_timestamp": "2026-05-14T08:59:28Z",
  "gross_sales": "140.00",
  "net_sales": "125.00",
  "promo_status": "WITHOUT_APPROVAL",
  "customer_code": "C-B1028"
}
```

For a new submission, generate new identifiers:

- `submission_uuid`: new UUID for the API envelope.
- `transaction_id`: new UUID for the sale event, unless this is a retry of the same sale.

For retries, reuse the original `transaction_id`.

## 2. Use The Required V2.1 Shape

V2.1 expects a single transaction object in Phase 1:

```json
{
  "submission_uuid": "NEW_UUID",
  "tenant_id": 16,
  "terminal_id": 97,
  "submission_timestamp": "2026-05-14T08:59:28Z",
  "transaction_count": 1,
  "transaction": {}
}
```

Do not send `transactions` as an array for Phase 1.

## 3. Put `hardware_id` Inside `transaction`

`hardware_id` is required inside the transaction object:

```json
{
  "transaction": {
    "hardware_id": "BUI-XTM80213"
  }
}
```

Do not put `hardware_id` only at the submission root. If it is missing from `transaction`, the ingestion layer can fall back to `terminal_id`, which is not the intended device identifier.

## 4. Format Money As Strict Strings

For V2.1, these fields must be strings with exactly two decimal places:

- `gross_sales`
- `net_sales`
- every `amount` inside `adjustments`
- every `amount` inside `taxes`

Valid:

```json
"gross_sales": "140.00"
```

Avoid:

```json
"gross_sales": 140
```

## 5. Reconcile Tax Buckets

For the example sale:

- `gross_sales = 140.00`
- `net_sales = 125.00`
- `VAT = 15.00`

The vatable base should be `125.00`, not `0.00`:

```json
[
  { "tax_type": "VAT", "amount": "15.00" },
  { "tax_type": "VATABLE_SALES", "amount": "125.00" },
  { "tax_type": "SC_VAT_EXEMPT_SALES", "amount": "0.00" },
  { "tax_type": "OTHER_TAX", "amount": "0.00" }
]
```

This keeps the business reconciliation aligned with:

```text
net_sales = VATABLE_SALES + SC_VAT_EXEMPT_SALES + VAT
gross_sales = net_sales + adjustments + OTHER_TAX
```

The exact formulas can vary by configuration, but invalid tax buckets can pass checksum validation and still fail business validation later.

## 6. Build The Transaction Before Hashing

Create the full transaction object without `payload_checksum` first:

```json
{
  "hardware_id": "BUI-XTM80213",
  "receipt_no": "000001072840",
  "transaction_id": "NEW_TRANSACTION_UUID",
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
  ]
}
```

## 7. Compute The Transaction Checksum

V2.1 checksum generation uses:

1. Remove `payload_checksum` from the object being hashed.
2. Recursively sort object keys alphabetically.
3. Preserve array item order.
4. Format monetary values as two-decimal strings.
5. JSON encode compactly with unescaped slashes and Unicode.
6. Hash the encoded string with SHA-256.

Important nested sorting example:

```json
{ "tax_type": "VAT", "amount": "15.00" }
```

is hashed canonically as:

```json
{ "amount": "15.00", "tax_type": "VAT" }
```

Use the local service:

```php
$transaction['payload_checksum'] = $checksumService->computeChecksum($transaction);
```

## 8. Compute The Submission Checksum

After setting `transaction.payload_checksum`, build the full submission:

```php
$payload = [
    'submission_uuid' => $submissionUuid,
    'tenant_id' => 16,
    'terminal_id' => 97,
    'submission_timestamp' => '2026-05-14T08:59:28Z',
    'transaction_count' => 1,
    'transaction' => $transaction,
];

$payload['payload_checksum'] = $checksumService->computeChecksum($payload);
```

The root checksum must include the full `transaction` object with its transaction checksum already present.

## 9. Validate The Payload Locally

Use the local validation helper:

```bash
php scripts/validate_and_correct_payload.php payload.json
```

Expected result:

```text
Payload is valid.
```

If it prints corrected checksums, use that output to identify the mismatch.

## 10. Full Development Snippet

This snippet generates a valid payload with the same sale details:

```php
<?php

require 'vendor/autoload.php';

use App\Services\PayloadChecksumService;
use Illuminate\Support\Str;

$checksumService = new PayloadChecksumService();

$transaction = [
    'hardware_id' => 'BUI-XTM80213',
    'receipt_no' => '000001072840',
    'transaction_id' => (string) Str::uuid(),
    'transaction_timestamp' => '2026-05-14T08:59:28Z',
    'gross_sales' => '140.00',
    'net_sales' => '125.00',
    'promo_status' => 'WITHOUT_APPROVAL',
    'customer_code' => 'C-B1028',
    'adjustments' => [
        ['adjustment_type' => 'promo_discount', 'amount' => '0.00'],
        ['adjustment_type' => 'senior_discount', 'amount' => '0.00'],
        ['adjustment_type' => 'pwd_discount', 'amount' => '0.00'],
        ['adjustment_type' => 'vip_card_discount', 'amount' => '0.00'],
        ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => '0.00'],
        ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => '0.00'],
        ['adjustment_type' => 'employee_discount', 'amount' => '0.00'],
    ],
    'taxes' => [
        ['tax_type' => 'VAT', 'amount' => '15.00'],
        ['tax_type' => 'VATABLE_SALES', 'amount' => '125.00'],
        ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => '0.00'],
        ['tax_type' => 'OTHER_TAX', 'amount' => '0.00'],
    ],
];

$transaction['payload_checksum'] = $checksumService->computeChecksum($transaction);

$payload = [
    'submission_uuid' => (string) Str::uuid(),
    'tenant_id' => 16,
    'terminal_id' => 97,
    'submission_timestamp' => '2026-05-14T08:59:28Z',
    'transaction_count' => 1,
    'transaction' => $transaction,
];

$payload['payload_checksum'] = $checksumService->computeChecksum($payload);

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
```

## 11. Common Failure Causes

| Symptom | Likely Cause | Fix |
| :--- | :--- | :--- |
| Transaction checksum mismatch | Hashed a different transaction object than the one sent | Recompute after finalizing all transaction fields |
| Submission checksum mismatch | Root hash computed before adding `transaction.payload_checksum` | Compute transaction checksum first, then root checksum |
| Checksum differs by platform | Nested keys were not sorted recursively | Sort keys in every object, including objects inside arrays |
| Valid checksum but processing fails | Tax buckets do not reconcile with sales totals | Check VAT, VATABLE_SALES, net, and gross formulas |
| `hardware_id` saved incorrectly | `hardware_id` missing inside `transaction` | Put `hardware_id` inside `transaction` before hashing |

## 12. Final Checklist

- [ ] `submission_uuid` is new for a new API attempt.
- [ ] `transaction_id` is new for a new sale event, reused for retries.
- [ ] `transaction_count` is `1`.
- [ ] `hardware_id` is inside `transaction`.
- [ ] Money values are strings with two decimal places.
- [ ] Tax buckets reconcile with gross and net sales.
- [ ] `transaction.payload_checksum` is computed first.
- [ ] Root `payload_checksum` is computed last.
- [ ] Local validator returns `Payload is valid.`
