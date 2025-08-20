# Transaction Validation Documentation

## Overview

The Transaction Validation system provides comprehensive validation for POS transactions, including request payload validation, business rules enforcement, and data integrity checks.

## Components

### 1. Request Validation

Located in `App\Http\Requests\TransactionRequest`

#### Required Fields

-   `terminal_id` - Terminal identifier (string)
-   `amount` - Transaction amount (numeric)
-   `type` - Transaction type (PAYMENT/VOID/REFUND)
-   `reference_number` - Unique transaction reference
-   `transaction_date` - Transaction timestamp
-   `metadata` - Optional additional data (array)

#### Example Request

```json
{
    "terminal_id": "TERM123",
    "amount": 1000.0,
    "type": "PAYMENT",
    "reference_number": "TXN-20240321-001",
    "transaction_date": "2024-03-21 14:30:00",
    "metadata": {
        "customer_id": "CUST001",
        "payment_method": "CASH"
    }
}
```

### 2. Business Rules Validation

Located in `App\Services\TransactionValidationService`

#### Operating Hours

-   Valid hours: 6:00 AM to 10:00 PM
-   Transactions outside these hours are rejected

#### Terminal Status

-   Terminal must be active
-   Inactive terminals cannot process transactions

#### Transaction Limits

-   Amount must not exceed terminal's configured limit
-   Default limit: 100,000

#### Validation Methods

```php
validateTransaction($data): array
isWithinOperatingHours($date): bool
isTerminalActive($terminalId): bool
isWithinTransactionLimit($amount, $terminalId): bool
```

### 3. Text Format Support

The system supports multiple text formats for legacy POS systems:

#### Supported Formats

```
KEY: VALUE
KEY=VALUE
KEY VALUE
```

#### Field Normalization

Common field mappings:

-   TENANT_ID, TENANTID → tenant_id
-   TX_ID, TXID → transaction_id
-   GROSS_SALES, GROSSSALES → gross_sales

## Integration

### 1. Middleware Usage

```php
use App\Http\Middleware\TransformTextFormat;

Route::post('/transactions', [TransactionController::class, 'store'])
    ->middleware(['transform.text']);
```

### 2. Validation Service Usage

```php
$service = app(TransactionValidationService::class);
$result = $service->validateTransaction($data);

if (!empty($result)) {
    // Handle validation errors
    return response()->json(['errors' => $result], 422);
}
```

## Error Handling

### Validation Errors

```json
{
    "errors": [
        "Transaction outside operating hours (6AM-10PM)",
        "Terminal is currently inactive"
    ]
}
```

### Response Codes

-   200: Successful validation
-   422: Validation failed
-   400: Invalid request format
-   500: Server error

## Testing

### Unit Tests

Located in `tests/Feature/TransactionValidationTest.php`

```php
test_validates_valid_transaction()
test_validates_operating_hours()
test_handles_invalid_input()
test_handles_special_characters()
```

### Running Tests

```bash
php artisan test --filter=TransactionValidationTest
```

## Configuration

### Operating Hours

```env
TRANSACTION_HOURS_START=6
TRANSACTION_HOURS_END=22
```

### Transaction Limits

Configure per terminal in the database:

```sql
ALTER TABLE pos_terminals ADD COLUMN transaction_limit DECIMAL(10,2);
```

## Logging

-   Validation errors are logged to `storage/logs/validation.log`
-   Debug level logging available for development

## Security Considerations

-   Input sanitization for text format parsing
-   Amount validation to prevent overflow
-   Tenant isolation for multi-tenant setup
-   Rate limiting per terminal

## Future Enhancements

-   [ ] Custom validation rules per tenant
-   [ ] Dynamic operating hours
-   [ ] Real-time limit adjustments
-   [ ] Enhanced error reporting
-   [ ] Audit trail improvements

## Support

For issues and support:

-   Email: support@tsms.dev
-   Documentation: /docs/api
-   Internal Wiki: /wiki/validation
