# Complete Payload Forwarding Implementation Guide

## Current Status ✅

Your TSMS system already has a **production-ready** webapp forwarding implementation:

### ✅ **Active Components:**
- **WebAppForwardingService** - Handles batch forwarding with circuit breaker
- **ForwardTransactionsToWebAppJob** - Async queue processing
- **ForwardTransactionsToWebApp** - CLI command with multiple modes
- **WebappTransactionForward** - Database tracking model
- **Configuration** - Complete settings in `config/tsms.php`

### ✅ **Current Configuration:**
```php
'web_app' => [
    'endpoint' => 'http://tsms-ops.test/api/transactions/bulk',
    'timeout' => 30,
    'batch_size' => 50,
    'auth_token' => 'tsms_7f8a2c1e_2025_ops_XYZ123',
    'verify_ssl' => false,
    'enabled' => true,  // ✅ ENABLED
]
```

### ✅ **Statistics:**
- ✅ 1 transaction successfully forwarded
- ✅ Circuit breaker: CLOSED (healthy)
- ✅ 0 failed forwards
- ✅ System operational

## How to Use the Existing Forwarding System

### 1. **Manual Forwarding (CLI)**
```bash
# Forward pending transactions
php artisan tsms:forward-transactions

# Dry run to see what would be forwarded
php artisan tsms:forward-transactions --dry-run

# Force forwarding (bypass circuit breaker)
php artisan tsms:forward-transactions --force

# Queue for async processing
php artisan tsms:forward-transactions --queue

# Show statistics
php artisan tsms:forward-transactions --stats
```

### 2. **Automatic Forwarding (Queue Job)**
```php
// Dispatch the forwarding job
ForwardTransactionsToWebAppJob::dispatch();
```

### 3. **Programmatic Forwarding**
```php
use App\Services\WebAppForwardingService;

$forwardingService = app(WebAppForwardingService::class);
$result = $forwardingService->forwardUnsentTransactions();

if ($result['success']) {
    echo "Forwarded {$result['forwarded_count']} transactions";
} else {
    echo "Failed: {$result['error']}";
}
```

## Payload Structure Forwarded to WebApp

Your system forwards **complete transaction payloads** with two different structures depending on the number of transactions being forwarded:

### **Structure 1: Single Transaction Forwarding**
When forwarding a single transaction, the payload uses this structure:

```json
{
  "submission_uuid": "807b61f5-1a49-42c1-9e42-dd0d197b4207",
  "tenant_id": 34,
  "terminal_id": 1,
  "submission_timestamp": "2025-09-09T12:00:00.000Z",
  "transaction_count": 1,
  "payload_checksum": "5b6b502c8bf68e7cdd7e913bd722b2cd40d76f6f28dc3f90eeb99ae24e7666bb",
  "transaction": {
    "transaction_id": "f47ac10b-58cc-4372-a567-0e02b2c3d529",
    "transaction_timestamp": "2025-09-09T12:00:00.000Z",
    "gross_sales": 465.00,
    "net_sales": 365.20,
    "promo_status": "WITH_APPROVAL",
    "customer_code": "C-C1045",
    "payload_checksum": "5b6b502c8bf68e7cdd7e913bd722b2cd40d76f6f28dc3f90eeb99ae24e7666bb",
    "adjustments": [
      {
        "adjustment_type": "promo_discount",
        "amount": 50.00
      },
      {
        "adjustment_type": "senior_discount",
        "amount": 0.00
      },
      {
        "adjustment_type": "pwd_discount",
        "amount": 0.00
      },
      {
        "adjustment_type": "vip_card_discount",
        "amount": 0.00
      },
      {
        "adjustment_type": "service_charge_distributed_to_employees",
        "amount": 0.00
      },
      {
        "adjustment_type": "service_charge_retained_by_management",
        "amount": 0.00
      },
      {
        "adjustment_type": "employee_discount",
        "amount": 0.00
      }
    ],
    "taxes": [
      {
        "tax_type": "VAT",
        "amount": 56.10
      },
      {
        "tax_type": "VATABLE_SALES",
        "amount": 0.00
      },
      {
        "tax_type": "SC_VAT_EXEMPT_SALES",
        "amount": 0.00
      },
      {
        "tax_type": "OTHER_TAX",
        "amount": 0.00
      }
    ]
  }
}
```

### **Structure 2: Batch Transaction Forwarding**
When forwarding multiple transactions, the payload uses this structure:

```json
{
  "source": "TSMS",
  "batch_id": "TSMS_20250909142019_68bfc7230447c",
  "timestamp": "2025-09-09T14:20:19.000Z",
  "transaction_count": 2,
  "transactions": [
    {
      "tsms_id": 1,
      "transaction_id": "f47ac10b-58cc-4372-a567-0e02b2c3d529",
      "terminal_serial": "J8N7C9P7P8943N",
      "tenant_code": "TENANT001",
      "tenant_name": "Sample Tenant",
      "transaction_timestamp": "2025-09-09T12:00:00.000Z",
      "amount": 465.00,
      "net_amount": 365.20,
      "validation_status": "VALID",
      "processed_at": "2025-09-09T12:00:00.000Z",
      "submission_uuid": "807b61f5-1a49-42c1-9e42-dd0d197b4207",
      "adjustments": [
        {
          "adjustment_type": "promo_discount",
          "amount": 50.00
        },
        {
          "adjustment_type": "senior_discount",
          "amount": 0.00
        },
        {
          "adjustment_type": "pwd_discount",
          "amount": 0.00
        },
        {
          "adjustment_type": "vip_card_discount",
          "amount": 0.00
        },
        {
          "adjustment_type": "service_charge_distributed_to_employees",
          "amount": 0.00
        },
        {
          "adjustment_type": "service_charge_retained_by_management",
          "amount": 0.00
        },
        {
          "adjustment_type": "employee_discount",
          "amount": 0.00
        }
      ],
      "taxes": [
        {
          "tax_type": "VAT",
          "amount": 56.10
        },
        {
          "tax_type": "VATABLE_SALES",
          "amount": 0.00
        },
        {
          "tax_type": "SC_VAT_EXEMPT_SALES",
          "amount": 0.00
        },
        {
          "tax_type": "OTHER_TAX",
          "amount": 0.00
        }
      ],
      "checksum": "5b6b502c8bf68e7cdd7e913bd722b2cd40d76f6f28dc3f90eeb99ae24e7666bb"
    }
  ]
}
```

### **Complete Adjustment Types**
The system always includes all 7 adjustment types, even if the amount is 0:

```json
"adjustments": [
  {"adjustment_type": "promo_discount", "amount": 0.00},
  {"adjustment_type": "senior_discount", "amount": 0.00},
  {"adjustment_type": "pwd_discount", "amount": 0.00},
  {"adjustment_type": "vip_card_discount", "amount": 0.00},
  {"adjustment_type": "service_charge_distributed_to_employees", "amount": 0.00},
  {"adjustment_type": "service_charge_retained_by_management", "amount": 0.00},
  {"adjustment_type": "employee_discount", "amount": 0.00}
]
```

### **Complete Tax Types**
The system always includes all 4 tax types, even if the amount is 0:

```json
"taxes": [
  {"tax_type": "VAT", "amount": 0.00},
  {"tax_type": "VATABLE_SALES", "amount": 0.00},
  {"tax_type": "SC_VAT_EXEMPT_SALES", "amount": 0.00},
  {"tax_type": "OTHER_TAX", "amount": 0.00}
]
```

### **Checksum Validation**
Each payload includes a `checksum` field calculated using the PayloadChecksumService. Your webapp should:

1. **Remove the checksum** from the payload before validation
2. **Recalculate the checksum** using the same algorithm
3. **Compare checksums** to ensure data integrity

### **Expected Response Format**
Your webapp should respond with HTTP 200 and a JSON response:

```json
{
  "success": true,
  "message": "Transactions processed successfully",
  "processed_count": 1,
  "batch_id": "TSMS_20250909142019_68bfc7230447c"
}
```

For errors, return appropriate HTTP status codes (400, 500) with error details:

```json
{
  "success": false,
  "error": "Validation failed",
  "details": "Invalid transaction format"
}
```

## WebApp API Implementation Requirements

### **Required API Endpoint**
Your webapp must implement this endpoint to receive TSMS transaction payloads:

```
POST /api/transactions/bulk
Authorization: Bearer {auth_token}
Content-Type: application/json
```

### **Authentication**
- **Bearer Token**: Use the token configured in `config/tsms.php` → `web_app.auth_token`
- **Token Format**: `tsms_7f8a2c1e_2025_ops_XYZ123` (example)
- **Header**: `Authorization: Bearer tsms_7f8a2c1e_2025_ops_XYZ123`

### **Request Handling**
```php
// Example Laravel controller implementation
public function receiveBulkTransactions(Request $request)
{
    // Validate bearer token
    $token = $request->bearerToken();
    if (!$token || $token !== config('tsms.web_app.auth_token')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $payload = $request->all();

    // Handle single transaction structure
    if (isset($payload['transaction'])) {
        return $this->processSingleTransaction($payload);
    }

    // Handle batch structure
    if (isset($payload['transactions'])) {
        return $this->processBatchTransactions($payload);
    }

    return response()->json(['error' => 'Invalid payload structure'], 400);
}
```

### **Single Transaction Processing**
```php
private function processSingleTransaction($payload)
{
    $transaction = $payload['transaction'];

    // Validate checksum
    $calculatedChecksum = $this->calculateChecksum($transaction);
    if ($calculatedChecksum !== $transaction['payload_checksum']) {
        return response()->json(['error' => 'Invalid checksum'], 400);
    }

    // Process the transaction
    // - Save to database
    // - Update financial records
    // - Trigger business logic

    return response()->json([
        'success' => true,
        'message' => 'Transaction processed successfully',
        'transaction_id' => $transaction['transaction_id']
    ]);
}
```

### **Batch Transaction Processing**
```php
private function processBatchTransactions($payload)
{
    $processed = 0;
    $errors = [];

    foreach ($payload['transactions'] as $transaction) {
        try {
            // Validate checksum for each transaction
            $calculatedChecksum = $this->calculateChecksum($transaction);
            if ($calculatedChecksum !== $transaction['checksum']) {
                $errors[] = "Invalid checksum for transaction {$transaction['transaction_id']}";
                continue;
            }

            // Process transaction
            // - Save to database
            // - Update records

            $processed++;
        } catch (\Exception $e) {
            $errors[] = "Failed to process transaction {$transaction['transaction_id']}: {$e->getMessage()}";
        }
    }

    return response()->json([
        'success' => count($errors) === 0,
        'processed_count' => $processed,
        'errors' => $errors,
        'batch_id' => $payload['batch_id']
    ]);
}
```

### **Checksum Calculation**
Implement the same checksum algorithm used by TSMS:

```php
private function calculateChecksum($data)
{
    // Remove checksum field if present
    unset($data['checksum']);

    // Sort keys recursively for consistent hashing
    $data = $this->sortKeysRecursive($data);

    // Convert to JSON and hash
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return hash('sha256', $json);
}

private function sortKeysRecursive($array)
{
    if (!is_array($array)) {
        return $array;
    }

    ksort($array);
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $array[$key] = $this->sortKeysRecursive($value);
        }
    }

    return $array;
}
```

### **Error Handling**
Return appropriate HTTP status codes:

- **200**: Success
- **400**: Bad Request (invalid payload, checksum mismatch)
- **401**: Unauthorized (invalid/missing token)
- **500**: Internal Server Error

### **Idempotency**
Implement idempotency using `submission_uuid` to prevent duplicate processing:

```php
// Check if submission was already processed
$existing = DB::table('processed_submissions')
    ->where('submission_uuid', $payload['submission_uuid'])
    ->first();

if ($existing) {
    return response()->json([
        'success' => true,
        'message' => 'Submission already processed',
        'idempotent' => true
    ]);
}
```

### 2. **Scheduled Forwarding**
Add to your console kernel or scheduler:

```php
// In app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('tsms:forward-transactions')
             ->everyFiveMinutes()
             ->withoutOverlapping();
}
```

### 3. **Real-time Forwarding**
For immediate forwarding after validation:

```php
// In your transaction validation logic
$forwardingService = app(WebAppForwardingService::class);
$result = $forwardingService->processUnforwardedTransactions();
```

## Testing Your WebApp Integration

### **Test Endpoint Setup**
Create a test endpoint to validate your implementation:

```php
// In routes/api.php
Route::post('/transactions/bulk-test', [TransactionController::class, 'testBulkEndpoint']);
```

```php
// In TransactionController
public function testBulkEndpoint(Request $request)
{
    // Log the incoming payload for debugging
    Log::info('Test payload received', $request->all());

    // Validate authentication
    $token = $request->bearerToken();
    if (!$token || $token !== config('tsms.web_app.auth_token')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $payload = $request->all();

    // Validate payload structure
    if (isset($payload['transaction'])) {
        // Single transaction validation
        $transaction = $payload['transaction'];

        // Check required fields
        $required = ['transaction_id', 'adjustments', 'taxes'];
        foreach ($required as $field) {
            if (!isset($transaction[$field])) {
                return response()->json(['error' => "Missing field: {$field}"], 400);
            }
        }

        // Validate adjustment types
        $expectedAdjustments = [
            'promo_discount', 'senior_discount', 'pwd_discount',
            'vip_card_discount', 'service_charge_distributed_to_employees',
            'service_charge_retained_by_management', 'employee_discount'
        ];

        $actualAdjustments = array_column($transaction['adjustments'], 'adjustment_type');
        sort($expectedAdjustments);
        sort($actualAdjustments);

        if ($expectedAdjustments !== $actualAdjustments) {
            return response()->json([
                'error' => 'Invalid adjustment types',
                'expected' => $expectedAdjustments,
                'received' => $actualAdjustments
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Single transaction payload validated',
            'transaction_id' => $transaction['transaction_id']
        ]);

    } elseif (isset($payload['transactions'])) {
        // Batch validation
        return response()->json([
            'success' => true,
            'message' => 'Batch payload validated',
            'transaction_count' => count($payload['transactions']),
            'batch_id' => $payload['batch_id'] ?? null
        ]);

    } else {
        return response()->json(['error' => 'Invalid payload structure'], 400);
    }
}
```

### **Test Commands**
Use these commands to test your integration:

```bash
# Test with a real transaction (if available)
php artisan tsms:forward-transactions --dry-run

# Check forwarding statistics
php artisan tsms:forward-transactions --stats

# View recent forwarding attempts
php artisan tinker --execute="dd(\App\Models\WebappTransactionForward::latest()->first()?->toArray());"
```

### **Integration Checklist**
- [ ] API endpoint `/api/transactions/bulk` implemented
- [ ] Bearer token authentication working
- [ ] Single transaction payload structure accepted
- [ ] Batch transaction payload structure accepted
- [ ] All 7 adjustment types validated
- [ ] All 4 tax types validated
- [ ] Checksum validation implemented
- [ ] Idempotency handling implemented
- [ ] Error responses properly formatted
- [ ] Success responses properly formatted
- [ ] Logging implemented for debugging

### **Common Issues & Solutions**

#### **401 Unauthorized**
```json
{"error": "Unauthorized"}
```
**Solution**: Check that the Bearer token in the Authorization header matches `config('tsms.web_app.auth_token')`

#### **400 Bad Request - Invalid Checksum**
```json
{"error": "Invalid checksum"}
```
**Solution**: Ensure your checksum calculation matches the TSMS algorithm (SHA256 of sorted JSON)

#### **400 Bad Request - Missing Fields**
```json
{"error": "Missing field: adjustments"}
```
**Solution**: Ensure all required fields are present in the payload

#### **400 Bad Request - Invalid Adjustment Types**
```json
{
  "error": "Invalid adjustment types",
  "expected": ["promo_discount", "senior_discount", ...],
  "received": ["promo_discount", "discount"]
}
```
**Solution**: Ensure all 7 adjustment types are included exactly as specified

## Security Features

### ✅ **Built-in Security:**
- **Bearer Token Authentication**
- **Checksum Validation** (prevents data tampering)
- **SSL Verification** (configurable)
- **Circuit Breaker** (prevents cascade failures)
- **Rate Limiting** (configurable batch sizes)

### ✅ **Audit Trail:**
- Complete request/response logging
- Attempt tracking with retry limits
- Status tracking (pending → in_progress → completed/failed)

## Performance Optimizations

### ✅ **Current Optimizations:**
- **Batch Processing** (50 transactions per batch)
- **Async Queue Processing** (Horizon/Laravel queues)
- **Circuit Breaker Pattern** (failure resilience)
- **Exponential Backoff** (retry logic)
- **Database Indexing** (optimized queries)

## Recommendations

### 1. **For Production Use:**
```bash
# Enable in .env
WEBAPP_FORWARDING_ENABLED=true
WEBAPP_FORWARDING_ENDPOINT=https://your-webapp.com/api/transactions/bulk
WEBAPP_FORWARDING_AUTH_TOKEN=your_secure_token
```

### 2. **Monitoring Setup:**
```php
// Add to your monitoring dashboard
$stats = app(WebAppForwardingService::class)->getForwardingStats();
```

### 3. **Alerting:**
```php
// Check circuit breaker status
if (app(WebAppForwardingService::class)->isCircuitBreakerOpen()) {
    // Send alert to administrators
}
```

## Summary

Your TSMS system **already has a complete, production-ready payload forwarding implementation**. The system:

- ✅ **Forwards complete transaction payloads** with all required data
- ✅ **Handles batch processing** efficiently
- ✅ **Includes comprehensive error handling** and retry logic
- ✅ **Provides circuit breaker protection** against failures
- ✅ **Tracks all forwarding attempts** with full audit trail
- ✅ **Supports both sync and async processing**
- ✅ **Is currently operational** (1 transaction already forwarded successfully)

### **What Your WebApp Needs to Implement:**

1. **API Endpoint**: `POST /api/transactions/bulk`
2. **Authentication**: Bearer token validation
3. **Payload Processing**: Handle both single and batch structures
4. **Data Validation**: Checksum validation and required fields
5. **Complete Types**: Process all 7 adjustment types and 4 tax types
6. **Idempotency**: Prevent duplicate processing
7. **Error Handling**: Proper HTTP status codes and responses

### **Key Points for WebApp Developers:**

- **Always include all adjustment/tax types** (even with 0 amounts)
- **Validate checksums** to ensure data integrity
- **Implement idempotency** using `submission_uuid`
- **Handle both payload structures** (single vs batch)
- **Return proper HTTP status codes** for different scenarios
- **Log all requests** for debugging and monitoring

The forwarding system is **ready for production use** and follows industry best practices for reliable data transmission. Once your webapp implements the requirements above, you can enable forwarding by updating the configuration in `config/tsms.php`.</content>
<parameter name="filePath">/Users/teamsolo/Projects/PITX/tsms-dev/WEBAPP_FORWARDING_GUIDELINES.md
