# TSMS WebApp Integration with Laravel Horizon - Deployment Guide

## Overview
This guide provides step-by-step instructions for setting up the WebApp with Laravel Horizon to receive and process TSMS transaction data asynchronously over WiFi between development machines.

## Prerequisites
- WebApp machine running Laravel with Laravel Horizon installed
- TSMS machine with forwarding service configured (already complete)
- Both machines on the same WiFi network
- PHP 8.1+ and Redis on both machines

## Part 1: WebApp Setup (Target Machine)

### 1.1 Install Laravel Horizon (if not already installed)
```bash
composer require laravel/horizon
php artisan horizon:install
php artisan migrate
```

### 1.2 Configure Horizon
Edit `config/horizon.php`:
```php
'environments' => [
    'production' => [
        'tsms-supervisor' => [
            'connection' => 'redis',
            'queue' => ['tsms-transactions', 'default'],
            'balance' => 'auto',
            'processes' => 3,
            'tries' => 3,
            'timeout' => 300,
            'nice' => 0,
        ],
    ],
    
    'local' => [
        'tsms-supervisor' => [
            'connection' => 'redis',
            'queue' => ['tsms-transactions', 'default'],
            'balance' => 'simple',
            'processes' => 1,
            'tries' => 3,
            'timeout' => 300,
        ],
    ],
],
```

### 1.3 Create Transaction Processing Job
Create `app/Jobs/ProcessTsmsTransactionBatch.php`:
```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTsmsTransactionBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    protected array $transactionData;
    protected string $batchId;

    public function __construct(array $transactionData, string $batchId)
    {
        $this->transactionData = $transactionData;
        $this->batchId = $batchId;
        $this->onQueue('tsms-transactions');
    }

    public function handle(): void
    {
        Log::info('Processing TSMS transaction batch', [
            'batch_id' => $this->batchId,
            'transaction_count' => count($this->transactionData['transactions'])
        ]);

        // Process each transaction in the batch
        foreach ($this->transactionData['transactions'] as $transaction) {
            $this->processTransaction($transaction);
        }

        Log::info('TSMS transaction batch processed successfully', [
            'batch_id' => $this->batchId
        ]);
    }

    protected function processTransaction(array $transaction): void
    {
        // Example processing logic - adapt to your WebApp's needs
        
        // 1. Validate transaction data
        $this->validateTransaction($transaction);
        
        // 2. Store/update in your WebApp database
        $this->storeTransaction($transaction);
        
        // 3. Trigger any aggregation/reporting updates
        $this->updateReports($transaction);
        
        Log::debug('Processed TSMS transaction', [
            'tsms_id' => $transaction['tsms_id'],
            'transaction_id' => $transaction['transaction_id'],
            'amount' => $transaction['amount']
        ]);
    }

    protected function validateTransaction(array $transaction): void
    {
        $required = ['tsms_id', 'transaction_id', 'amount', 'validation_status'];
        
        foreach ($required as $field) {
            if (!isset($transaction[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }
        
        if ($transaction['validation_status'] !== 'VALID') {
            throw new \InvalidArgumentException("Invalid transaction status: {$transaction['validation_status']}");
        }
    }

    protected function storeTransaction(array $transaction): void
    {
        // Implement your storage logic here
        // This could be storing to a transactions table, updating aggregates, etc.
        
        // Example structure:
        /*
        DB::table('webapp_transactions')->updateOrInsert(
            ['tsms_id' => $transaction['tsms_id']],
            [
                'transaction_id' => $transaction['transaction_id'],
                'amount' => $transaction['amount'],
                'terminal_serial' => $transaction['terminal_serial'],
                'tenant_code' => $transaction['tenant_code'],
                'tenant_name' => $transaction['tenant_name'],
                'transaction_timestamp' => $transaction['transaction_timestamp'],
                'processed_at' => $transaction['processed_at'],
                'checksum' => $transaction['checksum'],
                'received_at' => now(),
            ]
        );
        */
    }

    protected function updateReports(array $transaction): void
    {
        // Implement any report/aggregation updates
        // This could trigger events, update caches, etc.
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('TSMS transaction batch processing failed', [
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
```

### 1.4 Create API Endpoint
Create `app/Http/Controllers/Api/TsmsTransactionController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTsmsTransactionBatch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TsmsTransactionController extends Controller
{
    public function bulkReceive(Request $request): JsonResponse
    {
        // Validate the bulk payload structure
        $validator = Validator::make($request->all(), [
            'source' => 'required|string|in:TSMS',
            'batch_id' => 'required|string',
            'timestamp' => 'required|string',
            'transaction_count' => 'required|integer|min:1',
            'transactions' => 'required|array|min:1',
            'transactions.*.tsms_id' => 'required|integer',
            'transactions.*.transaction_id' => 'required|string',
            'transactions.*.amount' => 'required|numeric|min:0',
            'transactions.*.validation_status' => 'required|string',
            'transactions.*.checksum' => 'required|string',
            'transactions.*.terminal_serial' => 'nullable|string',
            'transactions.*.tenant_code' => 'nullable|string',
            'transactions.*.tenant_name' => 'nullable|string',
            'transactions.*.transaction_timestamp' => 'required|string',
            'transactions.*.processed_at' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::warning('TSMS bulk transaction validation failed', [
                'batch_id' => $request->input('batch_id'),
                'errors' => $validator->errors()->toArray()
            ]);
            
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $batchId = $request->input('batch_id');
        $transactionCount = $request->input('transaction_count');

        Log::info('Received TSMS transaction batch', [
            'batch_id' => $batchId,
            'transaction_count' => $transactionCount,
            'payload_size' => strlen(json_encode($request->all()))
        ]);

        try {
            // Queue the processing job for async handling by Horizon
            ProcessTsmsTransactionBatch::dispatch($request->all(), $batchId);

            return response()->json([
                'success' => true,
                'batch_id' => $batchId,
                'received_count' => $transactionCount,
                'status' => 'queued_for_processing',
                'message' => 'Transaction batch queued for processing'
            ], 202);

        } catch (\Exception $e) {
            Log::error('Failed to queue TSMS transaction batch', [
                'batch_id' => $batchId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to queue transaction batch',
                'batch_id' => $batchId
            ], 500);
        }
    }
}
```

### 1.5 Add API Routes
In `routes/api.php`:
```php
// TSMS Integration Routes
Route::middleware(['auth:sanctum'])->prefix('transactions')->group(function () {
    Route::post('/bulk', [App\Http\Controllers\Api\TsmsTransactionController::class, 'bulkReceive']);
});

// If using bearer token authentication instead of Sanctum:
// Route::middleware(['auth.bearer'])->prefix('transactions')->group(function () {
//     Route::post('/bulk', [App\Http\Controllers\Api\TsmsTransactionController::class, 'bulkReceive']);
// });
```

### 1.6 Configure Authentication Middleware
If using bearer token auth, create `app/Http/Middleware/BearerTokenAuth.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BearerTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $expectedToken = config('app.tsms_api_token');

        if (!$token || !$expectedToken || $token !== $expectedToken) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
```

Register in `app/Http/Kernel.php`:
```php
protected $middlewareAliases = [
    // ... existing middleware
    'auth.bearer' => \App\Http\Middleware\BearerTokenAuth::class,
];
```

## Part 2: Network Configuration

### 2.1 Find WebApp Machine IP
On the WebApp machine:
```bash
# Get the local IP address
ifconfig | grep "inet " | grep -v 127.0.0.1 | awk '{print $2}'
# Or on newer systems:
ip addr show | grep "inet " | grep -v 127.0.0.1 | awk '{print $2}' | cut -d/ -f1
```

### 2.2 Configure WebApp Server
Start Laravel development server on all interfaces:
```bash
# Start on all interfaces (accessible from other machines)
php artisan serve --host=0.0.0.0 --port=8000

# Or use specific IP
php artisan serve --host=192.168.1.100 --port=8000
```

### 2.3 Start Horizon
```bash
php artisan horizon
```

### 2.4 Verify Network Access
From TSMS machine, test connectivity:
```bash
# Test basic connectivity
ping 192.168.1.100

# Test HTTP access
curl -i http://192.168.1.100:8000/api/transactions/bulk \
  -H "Authorization: Bearer your-webapp-token" \
  -H "Content-Type: application/json" \
  -d '{"test": "connectivity"}'
```

## Part 3: TSMS Configuration Update

### 3.1 Update TSMS .env
```bash
# WebApp Integration Settings
TSMS_WEBAPP_ENABLED=true
TSMS_WEBAPP_ENDPOINT=http://192.168.1.100:8000
TSMS_WEBAPP_AUTH_TOKEN=your-shared-bearer-token
TSMS_WEBAPP_TIMEOUT=30
TSMS_WEBAPP_BATCH_SIZE=50
TSMS_WEBAPP_VERIFY_SSL=false
```

### 3.2 Test the Integration
```bash
# Test with dry run first
php artisan tsms:forward-transactions --dry-run

# Forward actual transactions
php artisan tsms:forward-transactions

# Use queue mode for Horizon processing on TSMS side (optional)
php artisan tsms:forward-transactions --queue
```

## Part 4: Monitoring and Verification

### 4.1 Monitor Horizon Dashboard
Access at: `http://192.168.1.100:8000/horizon`

### 4.2 Monitor Logs
WebApp machine:
```bash
# Monitor application logs
tail -f storage/logs/laravel.log

# Monitor Horizon logs
tail -f storage/logs/horizon.log
```

TSMS machine:
```bash
# Monitor forwarding logs
tail -f storage/logs/laravel.log | grep -i webapp
```

### 4.3 Check Queue Status
```bash
# On WebApp machine
php artisan queue:monitor
php artisan horizon:status
```

### 4.4 Verify Data Processing
Check that transactions are being processed:
```bash
# On WebApp machine - check your transaction storage
php artisan tinker
# Then in tinker:
DB::table('webapp_transactions')->count();
DB::table('webapp_transactions')->latest()->take(5)->get();
```

## Part 5: Production Considerations

### 5.1 Supervisor Configuration (Production)
Create `/etc/supervisor/conf.d/webapp-horizon.conf`:
```ini
[program:webapp-horizon]
process_name=%(program_name)s
command=php /path/to/webapp/artisan horizon
directory=/path/to/webapp
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/path/to/webapp/storage/logs/horizon.log
stopwaitsecs=3600
```

### 5.2 SSL/HTTPS Setup
For production, use HTTPS:
```bash
# Update TSMS .env
TSMS_WEBAPP_ENDPOINT=https://your-webapp-domain.com
TSMS_WEBAPP_VERIFY_SSL=true
```

### 5.3 Rate Limiting
Add rate limiting to the API endpoint:
```php
Route::middleware(['auth.bearer', 'throttle:60,1'])->prefix('transactions')->group(function () {
    Route::post('/bulk', [App\Http\Controllers\Api\TsmsTransactionController::class, 'bulkReceive']);
});
```

### 5.4 Database Optimization
Consider indexing for the WebApp transaction table:
```sql
CREATE INDEX idx_webapp_transactions_tsms_id ON webapp_transactions(tsms_id);
CREATE INDEX idx_webapp_transactions_batch_received ON webapp_transactions(received_at);
CREATE INDEX idx_webapp_transactions_terminal ON webapp_transactions(terminal_serial);
```

## Troubleshooting

### Connection Issues
1. Check firewall settings on both machines
2. Verify IP addresses and ports
3. Test with curl from TSMS machine
4. Check Laravel logs for authentication errors

### Queue Issues
1. Verify Redis is running on WebApp machine
2. Check Horizon status: `php artisan horizon:status`
3. Restart Horizon if needed: `php artisan horizon:terminate && php artisan horizon`
4. Monitor failed jobs: `php artisan queue:failed`

### Performance Issues
1. Increase batch size in TSMS config
2. Adjust Horizon process count
3. Monitor memory usage during processing
4. Consider database connection pooling

## Success Criteria
✅ WebApp receives TSMS transaction batches  
✅ Horizon processes jobs asynchronously  
✅ All transactions are stored/processed correctly  
✅ No impact on existing TSMS operations  
✅ Proper error handling and logging  
✅ Network communication stable over WiFi  

This completes the production-ready TSMS-WebApp integration with Laravel Horizon for async processing.
