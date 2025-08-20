# TSMS API Payload Checksum Guide

## Overview
The TSMS API uses **dual-layer SHA-256 checksums** for data integrity verification:
1. **Transaction-level checksum** (`transaction.payload_checksum`)
2. **Submission-level checksum** (`payload_checksum`)

## Checksum Calculation Process

### Step 1: Transaction Checksum
Calculate the SHA-256 checksum of the transaction object **WITHOUT** the `payload_checksum` field:

```php
$transaction = [
    "transaction_id" => "9861431d-afa9-4415-a7c8-f8d52b26bffd",
    "transaction_timestamp" => "2025-07-31T10:59:00Z",
    "base_amount" => 12345.67,
    "adjustments" => [...],
    "taxes" => [...]
    // NO payload_checksum field here
];

$transactionChecksum = $checksumService->computeChecksum($transaction);
```

### Step 2: Add Transaction Checksum
Add the computed checksum to the transaction object:

```php
$transaction['payload_checksum'] = $transactionChecksum;
```

### Step 3: Submission Checksum
Calculate the SHA-256 checksum of the entire submission **WITHOUT** the top-level `payload_checksum` field:

```php
$submission = [
    "submission_uuid" => "807b61f5-1a49-42c1-9e42-dd0d197b4207",
    "tenant_id" => 125,
    "terminal_id" => 1,
    "submission_timestamp" => "2025-07-31T10:59:00Z",
    "transaction_count" => 1,
    "transaction" => $transaction  // With payload_checksum included
    // NO payload_checksum field here at submission level
];

$submissionChecksum = $checksumService->computeChecksum($submission);
```

### Step 4: Final Payload
Add the submission checksum to complete the payload:

```php
$submission['payload_checksum'] = $submissionChecksum;
```

## Common Mistakes

### ❌ Wrong: Including checksum fields in calculation
```php
// WRONG - includes payload_checksum in calculation
$transaction['payload_checksum'] = "some-value";
$checksum = computeChecksum($transaction); // Will be incorrect!
```

### ✅ Correct: Exclude checksum fields from calculation
```php
// CORRECT - exclude payload_checksum from calculation
unset($transaction['payload_checksum']);
$checksum = computeChecksum($transaction);
$transaction['payload_checksum'] = $checksum;
```

## Field Order Sensitivity
The checksum calculation is **NOT** sensitive to field order. The `PayloadChecksumService` canonicalizes the data before hashing.

## Example Validation
```php
$checksumService = new PayloadChecksumService();
$result = $checksumService->validateSubmissionChecksums($payload);

if (!$result['valid']) {
    // Handle validation errors
    foreach ($result['errors'] as $error) {
        echo "Error: " . $error . "\n";
    }
}
```

## Debug Tips
1. **Use the `PayloadChecksumService`** - Don't implement your own checksum calculation
2. **Verify field exclusion** - Ensure checksum fields are excluded during calculation
3. **Check data types** - Ensure numeric values are properly typed (not strings)
4. **Use the debugging script** - Run `php fix-checksums.php` to see correct checksums
