# TSMS WebApp Integration Quick Reference

## Overview
TSMS forwards validated POS transactions to your WebApp every 5 minutes via a bulk HTTP API call.

## Endpoint Requirements
- **URL**: `POST /api/transactions/bulk`
- **Authentication**: Bearer token
- **Content-Type**: `application/json`
- **Timeout**: Respond within 30 seconds
- **Rate Limit**: Handle up to 60 requests/minute

## Exact Payload Structure

### Batch Container
```json
{
    "source": "TSMS",                          // Always "TSMS"
    "batch_id": "TSMS_20250712143000_abc123",  // Unique batch ID
    "timestamp": "2025-07-12T14:30:00.000Z",   // Batch creation time (.000Z format)
    "transaction_count": 25,                   // Number of transactions
    "transactions": [...]                      // Array of transactions
}
```

### Transaction Object
```json
{
    // REQUIRED FIELDS (never null)
    "tsms_id": 12345,                          // TSMS internal ID
    "transaction_id": "TX001",                 // POS transaction ID
    "amount": 100.50,                          // Transaction amount
    "validation_status": "VALID",              // Always "VALID"
    "checksum": "abc123def456",                // TSMS checksum
    "submission_uuid": "uuid-here",            // Unique submission ID
    
    // NULLABLE FIELDS (can be null)
    "terminal_serial": "T001",                 // Can be null
    "tenant_code": "TENANT001",                // Can be null
    "tenant_name": "Store ABC",                // Can be null
    "transaction_timestamp": "2025-07-12T14:25:00.000Z",  // Can be null
    "processed_at": "2025-07-12T14:25:30.000Z"            // Can be null
}
```

## Required Response Format

### Success (HTTP 200)
```json
{
    "status": "success",
    "received_count": 25,
    "batch_id": "TSMS_20250712143000_abc123",
    "processed_at": "2025-07-12T14:30:15.000Z"
}
```

### Error (HTTP 400/422/500)
```json
{
    "status": "error",
    "error_code": "VALIDATION_ERROR",
    "message": "Invalid transaction data",
    "batch_id": "TSMS_20250712143000_abc123"
}
```

## Database Schema (SQL)
```sql
CREATE TABLE webapp_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- REQUIRED (never null)
    tsms_id BIGINT UNSIGNED NOT NULL UNIQUE,
    transaction_id VARCHAR(255) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    validation_status VARCHAR(20) NOT NULL DEFAULT 'VALID',
    checksum VARCHAR(255) NOT NULL,
    submission_uuid VARCHAR(255) NOT NULL,
    
    -- NULLABLE (handle nulls)
    terminal_serial VARCHAR(255) NULL,
    tenant_code VARCHAR(255) NULL,
    tenant_name VARCHAR(255) NULL,
    transaction_timestamp TIMESTAMP NULL,
    processed_at TIMESTAMP NULL,
    
    -- WebApp metadata
    batch_id VARCHAR(255) NOT NULL,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_tsms_id (tsms_id),
    INDEX idx_batch_id (batch_id)
);
```

## Laravel Horizon Implementation (Recommended)

### 1. Queue Job for Processing Transactions
```php
// app/Jobs/ProcessTSMSTransactionBatch.php
<?php

namespace App\Jobs;

use App\Models\WebAppTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProcessTSMSTransactionBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes
    public int $tries = 3;

    public function __construct(
        private array $batchData,
        private string $batchId
    ) {}

    public function handle(): array
    {
        $startTime = microtime(true);
        
        Log::info('Processing TSMS batch', [
            'batch_id' => $this->batchId,
            'transaction_count' => count($this->batchData['transactions'])
        ]);

        DB::beginTransaction();
        try {
            $processedCount = 0;
            $duplicateCount = 0;

            foreach ($this->batchData['transactions'] as $transaction) {
                // Check for duplicates using tsms_id
                if (WebAppTransaction::where('tsms_id', $transaction['tsms_id'])->exists()) {
                    $duplicateCount++;
                    continue;
                }

                // Create transaction record
                WebAppTransaction::create([
                    'tsms_id' => $transaction['tsms_id'],
                    'transaction_id' => $transaction['transaction_id'],
                    'amount' => $transaction['amount'],
                    'validation_status' => $transaction['validation_status'],
                    'checksum' => $transaction['checksum'],
                    'submission_uuid' => $transaction['submission_uuid'],
                    
                    // Handle nullable fields gracefully
                    'terminal_serial' => $transaction['terminal_serial'] ?? null,
                    'tenant_code' => $transaction['tenant_code'] ?? null,
                    'tenant_name' => $transaction['tenant_name'] ?? null,
                    'transaction_timestamp' => isset($transaction['transaction_timestamp']) 
                        ? Carbon::parse($transaction['transaction_timestamp']) : null,
                    'processed_at' => isset($transaction['processed_at']) 
                        ? Carbon::parse($transaction['processed_at']) : null,
                    
                    'batch_id' => $this->batchId,
                    'received_at' => now(),
                ]);

                $processedCount++;
            }

            DB::commit();

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'status' => 'success',
                'received_count' => $processedCount,
                'duplicate_count' => $duplicateCount,
                'batch_id' => $this->batchId,
                'processed_at' => now()->toISOString(),
                'processing_time_ms' => $processingTime
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process TSMS batch', [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
```

### 2. Updated Controller with Horizon Integration
```php
// app/Http/Controllers/Api/TransactionController.php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTSMSTransactionBatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    public function receiveBulk(Request $request)
    {
        $validated = $request->validate([
            'source' => 'required|string|in:TSMS',
            'batch_id' => 'required|string',
            'timestamp' => 'required|date_format:Y-m-d\TH:i:s.v\Z',
            'transaction_count' => 'required|integer|min:1',
            'transactions' => 'required|array|min:1',
            
            // Required transaction fields
            'transactions.*.tsms_id' => 'required|integer',
            'transactions.*.transaction_id' => 'required|string',
            'transactions.*.amount' => 'required|numeric|min:0',
            'transactions.*.validation_status' => 'required|in:VALID',
            'transactions.*.checksum' => 'required|string',
            'transactions.*.submission_uuid' => 'required|string',
            
            // Nullable transaction fields
            'transactions.*.terminal_serial' => 'nullable|string',
            'transactions.*.tenant_code' => 'nullable|string',
            'transactions.*.tenant_name' => 'nullable|string',
            'transactions.*.transaction_timestamp' => 'nullable|date_format:Y-m-d\TH:i:s.v\Z',
            'transactions.*.processed_at' => 'nullable|date_format:Y-m-d\TH:i:s.v\Z',
        ]);

        Log::info('TSMS batch received', [
            'batch_id' => $validated['batch_id'],
            'transaction_count' => count($validated['transactions']),
            'client_ip' => $request->ip()
        ]);

        // For small batches (< 10 transactions), process synchronously
        if (count($validated['transactions']) < 10) {
            try {
                $job = new ProcessTSMSTransactionBatch($validated, $validated['batch_id']);
                $result = $job->handle();
                return response()->json($result);
                
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'error_code' => 'PROCESSING_ERROR',
                    'message' => 'Failed to process transaction batch',
                    'batch_id' => $validated['batch_id']
                ], 500);
            }
        }

        // For larger batches, queue for background processing
        try {
            ProcessTSMSTransactionBatch::dispatch($validated, $validated['batch_id'])
                ->onQueue('tsms-processing')
                ->afterCommit();

            return response()->json([
                'status' => 'accepted',
                'message' => 'Batch queued for processing',
                'batch_id' => $validated['batch_id'],
                'received_count' => count($validated['transactions']),
                'queued_at' => now()->toISOString()
            ], 202); // HTTP 202 Accepted

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'QUEUE_ERROR',
                'message' => 'Failed to queue batch for processing',
                'batch_id' => $validated['batch_id']
            ], 500);
        }
    }
}
```

### 3. Routes and Middleware
```php
// routes/api.php
use App\Http\Controllers\Api\TransactionController;

Route::middleware(['auth.bearer'])->group(function () {
    Route::post('/transactions/bulk', [TransactionController::class, 'receiveBulk']);
});

// app/Http/Middleware/BearerAuth.php
<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BearerAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $expectedToken = config('app.tsms_bearer_token', 'tsms_bearer_token_12345');
        
        if ($token !== $expectedToken) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'UNAUTHORIZED',
                'message' => 'Invalid or missing Bearer token'
            ], 401);
        }
        
        return $next($request);
    }
}
```

### 4. Horizon Configuration
```php
// config/horizon.php
'environments' => [
    'production' => [
        'tsms-processing-supervisor' => [
            'connection' => 'redis',
            'queue' => ['tsms-processing'],
            'balance' => 'auto',
            'processes' => 10,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 300,
        ],
    ],
    
    'local' => [
        'tsms-processing-supervisor' => [
            'connection' => 'redis', 
            'queue' => ['tsms-processing'],
            'balance' => 'simple',
            'processes' => 3,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 120,
        ],
    ],
],
```

### 5. WebApp Environment Configuration
```bash
# .env (WebApp)
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
TSMS_BEARER_TOKEN=tsms_bearer_token_12345
```

## WiFi Network Integration Setup

### 1. Network Configuration
Since TSMS is connected via WiFi, find both machines' IP addresses:

```bash
# On TSMS machine (find WiFi IP)
ifconfig en0 | grep "inet " | grep -v 127.0.0.1

# On WebApp machine (find WiFi IP)  
ifconfig en0 | grep "inet " | grep -v 127.0.0.1
```

### 2. TSMS Configuration for WiFi
```bash
# Update TSMS .env with WebApp's WiFi IP
WEBAPP_FORWARDING_ENDPOINT=http://192.168.1.105:8000/api/transactions/bulk
WEBAPP_FORWARDING_AUTH_TOKEN=tsms_bearer_token_12345
WEBAPP_FORWARDING_TIMEOUT=45
WEBAPP_FORWARDING_BATCH_SIZE=50
WEBAPP_FORWARDING_VERIFY_SSL=false
```

### 3. Start WebApp Services
```bash
# On WebApp machine
php artisan serve --host=0.0.0.0 --port=8000
php artisan horizon

# Monitor Horizon status
php artisan horizon:status
```

### 4. Test WiFi Connectivity
```bash
# From TSMS machine (replace with actual WebApp IP)
ping 192.168.1.105

# Test endpoint connectivity
curl -X POST http://192.168.1.105:8000/api/transactions/bulk \
  -H "Authorization: Bearer tsms_bearer_token_12345" \
  -H "Content-Type: application/json" \
  -d '{"source":"TSMS","batch_id":"WIFI_TEST","timestamp":"2025-07-13T10:30:00.000Z","transaction_count":0,"transactions":[]}'
```

## Horizon Management Commands

### On WebApp Machine:
```bash
# Start Horizon
php artisan horizon

# Check status
php artisan horizon:status

# Monitor queues
php artisan queue:monitor tsms-processing

# Retry failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan horizon:clear

# View logs
tail -f storage/logs/laravel.log | grep "TSMS"
```

### On TSMS Machine:
```bash
# Test forwarding
php artisan tsms:forward-transactions --dry-run
php artisan tsms:forward-transactions

# Check status
php artisan tsms:forwarding-status

# Monitor logs
tail -f storage/logs/laravel.log | grep -i "webapp"
```

## Critical Implementation Notes

1. **NULL Handling**: Fields marked as nullable CAN be `null` - handle gracefully
2. **Timestamps**: Use format `Y-m-d\TH:i:s.v\Z` (includes milliseconds)
3. **Duplicates**: Use `tsms_id` as primary duplicate check (most reliable)
4. **Batch Processing**: Use database transactions for atomic processing
5. **Error Responses**: Always include `batch_id` in error responses
6. **Authentication**: Validate Bearer token for security
7. **Performance**: Respond within 30 seconds for batches up to 1000 transactions

## Testing Payload
```json
{
    "source": "TSMS",
    "batch_id": "TEST_BATCH_001",
    "timestamp": "2025-07-12T14:30:00.000Z",
    "transaction_count": 1,
    "transactions": [
        {
            "tsms_id": 1,
            "transaction_id": "TEST_TX001",
            "terminal_serial": null,
            "tenant_code": "TEST",
            "tenant_name": "Test Store",
            "transaction_timestamp": "2025-07-12T14:25:00.000Z",
            "amount": 100.50,
            "validation_status": "VALID",
            "processed_at": null,
            "checksum": "test_checksum",
            "submission_uuid": "test-uuid-001"
        }
    ]
}
```

## Ready for Integration?
- ✅ Endpoint implemented and tested
- ✅ Database schema matches exact field structure
- ✅ Null handling implemented for all nullable fields
- ✅ Duplicate detection using `tsms_id`
- ✅ Authentication configured
- ✅ Error handling returns proper response format
- ✅ Laravel Horizon configured for async processing
- ✅ WiFi network connectivity established
- ✅ Performance tested with large batches

## Horizon Integration Benefits

### ✅ Performance Advantages:
1. **🚀 Async Processing**: Large batches (10+ transactions) processed in background
2. **⚡ Quick Response**: Small batches processed immediately (< 1 second)
3. **📊 Scalability**: Multiple workers handle high transaction volumes
4. **🔄 Reliability**: Automatic retry logic with exponential backoff
5. **🛡️ Stability**: Worker memory management and automatic restarts

### ✅ Response Types:
```json
// Small batches (< 10 transactions) - Immediate processing
{
    "status": "success",
    "received_count": 5,
    "batch_id": "TSMS_20250713_001",
    "processed_at": "2025-07-13T10:30:15.000Z",
    "processing_time_ms": 125.50
}

// Large batches (10+ transactions) - Queued processing  
{
    "status": "accepted",
    "message": "Batch queued for processing",
    "batch_id": "TSMS_20250713_002", 
    "received_count": 50,
    "queued_at": "2025-07-13T10:30:15.000Z"
}
```

### ✅ Monitoring Dashboard:
- Access Horizon dashboard at: `http://webapp-ip:8000/horizon`
- Real-time job monitoring and metrics
- Failed job management and retry capabilities
- Performance analytics and throughput tracking

**Contact TSMS team when ready for production configuration!**
