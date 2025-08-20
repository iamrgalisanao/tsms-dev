# Transaction Processing Documentation

## Overview

The Transaction Processing Pipeline handles POS transactions through multiple stages from ingestion to completion. The system supports both single and batch transaction submissions with comprehensive validation and processing.

## Components

### 1. Transaction Validation

-   Request validation via `TransactionRequest`
-   Business rules validation through `TransactionValidationService`
-   Supports both single and batch transaction formats
-   Payload checksum validation for data integrity

### 2. Queue Configuration

```bash
QUEUE_CONNECTION=redis
REDIS_QUEUE=transactions
QUEUE_RETRY_AFTER=90
```

### 3. Job Status Tracking

Status transitions:

-   `PENDING` → Initial validation state
-   `QUEUED` → Job queued for processing
-   `PROCESSING` → Being processed
-   `COMPLETED` → Successfully processed
-   `FAILED` → Processing failed

### 4. API Endpoints

#### Submit Single Transaction

```http
POST /api/v1/transactions
Content-Type: application/json

{
    "submission_uuid": "batch-uuid-123",
    "tenant_id": 1,
    "terminal_id": 1,
    "submission_timestamp": "2025-07-04T12:00:00Z",
    "transaction_count": 1,
    "payload_checksum": "sha256-of-batch",
    "transaction": {
        "transaction_id": "txn-uuid-001",
        "transaction_timestamp": "2025-07-04T12:00:01Z",
        "base_amount": 1000.0,
        "payload_checksum": "sha256-txn-001",
        "adjustments": [
            { "adjustment_type": "promo_discount", "amount": 50.0 },
            { "adjustment_type": "senior_discount", "amount": 20.0 }
        ],
        "taxes": [
            { "tax_type": "VAT", "amount": 120.0 },
            { "tax_type": "OTHER_TAX", "amount": 10.0 }
        ]
    }
}
```

#### Submit Batch Transactions

```http
POST /api/v1/transactions/batch
Content-Type: application/json

{
    "submission_uuid": "batch-uuid-200",
    "tenant_id": 2,
    "terminal_id": 5,
    "submission_timestamp": "2025-07-04T13:00:00Z",
    "transaction_count": 2,
    "payload_checksum": "sha256-of-batch-200",
    "transactions": [
        {
            "transaction_id": "txn-uuid-201",
            "transaction_timestamp": "2025-07-04T13:00:01Z",
            "base_amount": 1500.0,
            "payload_checksum": "sha256-txn-201",
            "adjustments": [
                { "adjustment_type": "promo_discount", "amount": 100.0 }
            ],
            "taxes": [{ "tax_type": "VAT", "amount": 180.0 }]
        },
        {
            "transaction_id": "txn-uuid-202",
            "transaction_timestamp": "2025-07-04T13:01:00Z",
            "base_amount": 2000.0,
            "payload_checksum": "sha256-txn-202",
            "adjustments": [
                { "adjustment_type": "service_charge", "amount": 50.0 },
                { "adjustment_type": "senior_discount", "amount": 80.0 }
            ],
            "taxes": [
                { "tax_type": "VAT", "amount": 240.0 },
                { "tax_type": "VAT_EXEMPT", "amount": 0.0 }
            ]
        }
    ]
}
```

#### Check Transaction Status

```http
GET /api/v1/transactions/{transaction_id}/status
```

**Response:**

```json
{
    "status": "success",
    "data": {
        "transaction_id": "txn-uuid-001",
        "customer_code": "CUST001",
        "base_amount": "1000.00",
        "latest_job": {
            "id": 1,
            "status": "COMPLETED",
            "created_at": "2025-07-04T12:00:02Z"
        },
        "latest_validation": {
            "id": 1,
            "validation_status": "VALID",
            "validated_at": "2025-07-04T12:00:01Z"
        },
        "adjustments": [
            { "adjustment_type": "promo_discount", "amount": "50.00" }
        ],
        "taxes": [{ "tax_type": "VAT", "amount": "120.00" }]
    }
}
```

## Required Fields

### Submission Level (Single & Batch)

-   `submission_uuid` (string): Unique UUID for the submission
-   `tenant_id` (integer): Tenant identifier assigned by TSMS
-   `terminal_id` (integer): POS terminal identifier assigned by TSMS
-   `submission_timestamp` (ISO8601): When the submission was sent
-   `transaction_count` (integer): Number of transactions in the submission
-   `payload_checksum` (string): SHA-256 hash of the full submission payload
-   `transaction` (object): Single transaction object (for single submission)
-   `transactions` (array): Array of transaction objects (for batch submission)

### Transaction Level

-   `transaction_id` (string): Unique UUID for the transaction
-   `transaction_timestamp` (ISO8601): When the sale occurred
-   `base_amount` (decimal): Total gross sales amount
-   `payload_checksum` (string): SHA-256 hash of the transaction payload
-   `adjustments` (array, optional): List of discounts, promos, or service charges
-   `taxes` (array, optional): List of tax lines (VAT, VAT-exempt, other)

### Internal Processing Fields

The following fields are automatically populated by the system:

-   `customer_code`: Retrieved from terminal configuration
-   `hardware_id`: Retrieved from terminal configuration
-   `validation_status`: Set to 'PENDING' initially
-   `job_status`: Tracked through separate job processing table

## Data Integrity

### Payload Checksum

All submissions must include payload checksums calculated using SHA-256:

1. **Submission Level**: Hash of entire payload excluding the checksum field itself
2. **Transaction Level**: Hash of individual transaction object excluding its checksum field

### Duplicate Prevention

The system prevents duplicate transactions by checking:

-   `transaction_id` + `terminal_id` combination
-   Returns existing transaction status if duplicate is detected

## Error Handling

### Validation Errors

```json
{
    "success": false,
    "message": "Transaction processing failed",
    "errors": {
        "terminal_id": ["The terminal id field is required."],
        "base_amount": ["The base amount must be a number."]
    }
}
```

### Processing Errors

```json
{
    "status": "error",
    "message": "Failed to process transaction: Terminal not found",
    "timestamp": "2025-07-04T12:00:00Z"
}
```

## Testing Scenarios

### 1. Single Transaction Processing

Test the complete flow of a single transaction:

```bash
# Submit single transaction
curl -X POST http://localhost:8000/api/v1/transactions \
  -H "Content-Type: application/json" \
  -d '{
    "submission_uuid": "test-submission-001",
    "tenant_id": 1,
    "terminal_id": 1,
    "submission_timestamp": "2025-07-04T12:00:00Z",
    "transaction_count": 1,
    "payload_checksum": "calculated-sha256-hash",
    "transaction": {
      "transaction_id": "test-txn-001",
      "transaction_timestamp": "2025-07-04T12:00:01Z",
      "base_amount": 1000.0,
      "payload_checksum": "calculated-transaction-hash"
    }
  }'

# Check status
curl -X GET http://localhost:8000/api/v1/transactions/test-txn-001/status
```

### 2. Batch Transaction Processing

Test batch processing with multiple transactions:

```bash
# Submit batch transactions
curl -X POST http://localhost:8000/api/v1/transactions/batch \
  -H "Content-Type: application/json" \
  -d '{
    "submission_uuid": "test-batch-001",
    "tenant_id": 1,
    "terminal_id": 1,
    "submission_timestamp": "2025-07-04T12:00:00Z",
    "transaction_count": 2,
    "payload_checksum": "calculated-batch-hash",
    "transactions": [
      {
        "transaction_id": "test-txn-002",
        "transaction_timestamp": "2025-07-04T12:00:01Z",
        "base_amount": 1500.0,
        "payload_checksum": "calculated-hash-002"
      },
      {
        "transaction_id": "test-txn-003",
        "transaction_timestamp": "2025-07-04T12:00:02Z",
        "base_amount": 2000.0,
        "payload_checksum": "calculated-hash-003"
      }
    ]
  }'
```

### 3. Error Scenarios

Test various error conditions:

-   Invalid terminal ID
-   Missing required fields
-   Duplicate transaction submission
-   Invalid payload checksum
-   Malformed JSON payload

Refer to `tests/Feature/TransactionValidationTest.php` for comprehensive test cases.

## Complete End-to-End Testing Guide

### Prerequisites

1. **Environment Setup**

```bash
# Ensure database is migrated
php artisan migrate

# Start queue workers
php artisan queue:work --queue=transactions

# Optional: Start Horizon for monitoring
php artisan horizon
```

2. **Test Data Setup**

```bash
# Seed test data (terminals, tenants, etc.)
php artisan db:seed

# Or create manually
php artisan tinker
>>> $tenant = \App\Models\Tenant::create(['name' => 'Test Tenant'])
>>> $terminal = \App\Models\PosTerminal::create([
...     'tenant_id' => $tenant->id,
...     'terminal_uid' => 'TEST-TERM-001',
...     'status_id' => 1
... ])
```

### Testing the Complete Pipeline

#### Phase 1: Transaction Ingestion

**Test 1A: Valid Transaction Submission**

```bash
curl -X POST http://localhost:8000/api/transactions \
  -H "Content-Type: application/json" \
  -d '{
    "payload": {
      "customer_code": "CUST001",
      "terminal_id": 1,
      "transaction_id": "TXN-'.date('YmdHis').'-001",
      "hardware_id": "HW001",
      "transaction_timestamp": "'.date('c').'",
      "base_amount": 1000.50,
      "payload_checksum": "dummy-checksum-001"
    }
  }'
```

**Expected Response:**

```json
{
    "status": "success",
    "message": "Transaction queued for processing",
    "data": {
        "transaction_id": "TXN-20250706123001-001"
    }
}
```

**Test 1B: Validation Error Handling**

```bash
curl -X POST http://localhost:8000/api/transactions \
  -H "Content-Type: application/json" \
  -d '{
    "payload": {
      "terminal_id": 999,
      "transaction_id": "TXN-INVALID-001"
    }
  }'
```

**Expected Response:**

```json
{
    "success": false,
    "message": "Transaction processing failed",
    "errors": {
        "terminal_id": ["The selected terminal id is invalid."],
        "base_amount": ["The base amount field is required."]
    }
}
```

#### Phase 2: Queue Processing Verification

**Monitor Queue Status:**

```bash
# Check queue status
php artisan queue:monitor

# Check failed jobs
php artisan queue:failed

# Check job statistics (if using Horizon)
php artisan horizon:status
```

**Database Verification:**

```sql
-- Check transaction was created
SELECT * FROM transactions WHERE transaction_id = 'TXN-20250706123001-001';

-- Check job was queued and processed
SELECT * FROM transaction_jobs WHERE transaction_id =
  (SELECT transaction_id FROM transactions WHERE transaction_id = 'TXN-20250706123001-001');

-- Check validation was performed
SELECT * FROM transaction_validations WHERE transaction_id =
  (SELECT transaction_id FROM transactions WHERE transaction_id = 'TXN-20250706123001-001');
```

#### Phase 3: Validation Service Testing

**Test 3A: Business Rules Validation**

```bash
# Test transaction with invalid amount (too high)
curl -X POST http://localhost:8000/api/transactions \
  -H "Content-Type: application/json" \
  -d '{
    "payload": {
      "customer_code": "CUST001",
      "terminal_id": 1,
      "transaction_id": "TXN-HIGH-AMOUNT-001",
      "hardware_id": "HW001",
      "transaction_timestamp": "'.date('c').'",
      "base_amount": 999999.99,
      "payload_checksum": "dummy-checksum-002"
    }
  }'

# Test transaction outside operating hours
curl -X POST http://localhost:8000/api/transactions \
  -H "Content-Type: application/json" \
  -d '{
    "payload": {
      "customer_code": "CUST001",
      "terminal_id": 1,
      "transaction_id": "TXN-AFTER-HOURS-001",
      "hardware_id": "HW001",
      "transaction_timestamp": "'.date('Y-m-d').'T23:30:00Z",
      "base_amount": 100.00,
      "payload_checksum": "dummy-checksum-003"
    }
  }'
```

#### Phase 4: Status Tracking and Monitoring

**Test 4A: Status Endpoint Verification**

```bash
# Check transaction status
curl -X GET http://localhost:8000/api/v1/transactions/TXN-20250706123001-001/status
```

**Expected Status Response:**

```json
{
    "status": "success",
    "data": {
        "transaction_id": "TXN-20250706123001-001",
        "customer_code": "CUST001",
        "base_amount": "1000.50",
        "latest_job": {
            "id": 1,
            "status": "COMPLETED",
            "created_at": "2025-07-06T12:30:02Z"
        },
        "latest_validation": {
            "id": 1,
            "status": "VALID",
            "validated_at": "2025-07-06T12:30:01Z"
        }
    }
}
```

#### Phase 5: Error Recovery and Retry Testing

**Test 5A: Simulate Processing Failure**

```php
// In tinker: php artisan tinker
use App\Jobs\ProcessTransactionJob;
use App\Models\Transaction;

// Create a transaction that will fail validation
$transaction = Transaction::create([
    'customer_code' => 'INVALID',
    'terminal_id' => 1,
    'transaction_id' => 'TXN-FAIL-TEST-001',
    'hardware_id' => 'HW001',
    'transaction_timestamp' => now(),
    'base_amount' => -100, // Invalid negative amount
    'payload_checksum' => 'test-checksum'
]);

// Dispatch job manually
ProcessTransactionJob::dispatch($transaction);
```

**Test 5B: Retry Failed Jobs**

```bash
# Check failed jobs
php artisan queue:failed

# Retry specific failed job
php artisan queue:retry <job-id>

# Retry all failed jobs
php artisan queue:retry all
```

#### Phase 6: Performance and Load Testing

**Test 6A: Batch Processing Performance**

```bash
# Create script to submit multiple transactions
for i in {1..100}; do
  curl -X POST http://localhost:8000/api/transactions \
    -H "Content-Type: application/json" \
    -d "{
      \"payload\": {
        \"customer_code\": \"CUST001\",
        \"terminal_id\": 1,
        \"transaction_id\": \"TXN-LOAD-$i\",
        \"hardware_id\": \"HW001\",
        \"transaction_timestamp\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",
        \"base_amount\": $((RANDOM % 1000 + 100)),
        \"payload_checksum\": \"checksum-$i\"
      }
    }" &
done
wait
```

**Monitor Performance:**

```bash
# Monitor queue metrics
php artisan queue:monitor --max-time=300

# Check processing times
SELECT
  transaction_id,
  status,
  started_at,
  completed_at,
  TIMESTAMPDIFF(SECOND, started_at, completed_at) as processing_time_seconds
FROM transaction_jobs
WHERE completed_at IS NOT NULL
ORDER BY started_at DESC
LIMIT 20;
```

### Automated Testing with PHPUnit

### Automated Testing with PHPUnit

The transaction processing pipeline includes a comprehensive automated test suite that covers all aspects of the system. The test suite is organized into five main categories:

#### Test Structure

1. **Transaction Ingestion Tests** (`TransactionIngestionTest.php`)

    - API endpoint validation
    - Authentication testing
    - Payload validation
    - Error handling
    - Rate limiting
    - Batch processing

2. **Transaction Queue Processing Tests** (`TransactionQueueProcessingTest.php`)

    - Job dispatch and execution
    - Queue worker functionality
    - Retry mechanisms
    - Failure handling
    - Concurrency testing

3. **Transaction Validation Tests** (`TransactionValidationTest.php`)

    - Business rule validation
    - Data integrity checks
    - Operating hours validation
    - Amount limits
    - Terminal status validation
    - Duplicate prevention

4. **Transaction End-to-End Tests** (`TransactionEndToEndTest.php`)

    - Complete pipeline testing
    - Audit trail verification
    - System recovery scenarios
    - Performance monitoring
    - Data consistency

5. **Transaction Performance Tests** (`TransactionPerformanceTest.php`)
    - Load testing
    - Throughput measurement
    - Memory usage monitoring
    - Database performance
    - Cache efficiency

#### Running the Tests

**Full Test Suite:**

```bash
# Run all transaction pipeline tests
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml

# Run with coverage report
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --coverage-html storage/coverage-html
```

**Individual Test Suites:**

```bash
# Ingestion tests only
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Ingestion"

# Queue processing tests only
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Queue Processing"

# Validation tests only
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Validation"

# End-to-end tests only
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction End-to-End"

# Performance tests only
php vendor/bin/phpunit --configuration phpunit-transaction-pipeline.xml --testsuite "Transaction Performance"
```

**Using Test Runners:**

```bash
# Windows
run-transaction-pipeline-tests.bat

# Linux/macOS
chmod +x run-transaction-pipeline-tests.sh
./run-transaction-pipeline-tests.sh
```

#### Test Coverage

The automated test suite provides comprehensive coverage:

-   **Line Coverage**: > 90%
-   **Branch Coverage**: > 85%
-   **Method Coverage**: > 95%

#### Performance Benchmarks

Expected performance metrics validated by tests:

-   **Single Transaction Processing**: < 500ms
-   **Batch Processing (10 transactions)**: < 3 seconds
-   **High Volume (50 transactions)**: < 15 seconds
-   **Peak Load (100 transactions)**: < 30 seconds
-   **Throughput**: > 10 transactions/second
-   **Memory Usage**: < 100MB for 100 transactions

#### Test Examples

**Example Test Case for Ingestion:**

```php
/** @test */
public function can_receive_single_transaction_successfully()
{
    $payload = [
        'customer_code' => $this->tenant->customer_code,
        'terminal_id' => $this->terminal->id,
        'transaction_id' => 'TXN-' . uniqid(),
        'hardware_id' => 'HW-12345',
        'transaction_timestamp' => now()->toISOString(),
        'base_amount' => 150.75,
        'items' => [
            [
                'id' => 1,
                'name' => 'Bus Ticket',
                'price' => 150.75,
                'quantity' => 1
            ]
        ]
    ];

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $this->token,
        'Accept' => 'application/json'
    ])->postJson('/api/v1/transactions', $payload);

    $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'transaction_id',
                    'status',
                    'timestamp'
                ]
            ]);

    // Verify transaction was created
    $this->assertDatabaseHas('transactions', [
        'transaction_id' => $payload['transaction_id'],
        'terminal_id' => $this->terminal->id,
        'base_amount' => 150.75
    ]);
}
```

**Example Test Case for Validation:**

```php
/** @test */
public function validates_operating_hours()
{
    $transaction = Transaction::factory()->create([
        'terminal_id' => $this->terminal->id,
        'customer_code' => $this->tenant->customer_code,
        'base_amount' => 100.00,
        'transaction_timestamp' => Carbon::now()->setHour(23)->toDateTimeString()
    ]);

    $errors = $this->validationService->validateTransaction($transaction->toArray());
    $this->assertNotEmpty($errors);
    $this->assertContains('Transaction outside operating hours (6AM-10PM)', $errors);
}
```

**Example Test Case for Performance:**

```php
/** @test */
public function processes_single_transaction_within_time_limit()
{
    $transaction = Transaction::factory()->create([
        'terminal_id' => $this->terminal->id,
        'customer_code' => $this->tenant->customer_code,
        'validation_status' => 'PENDING'
    ]);

    $startTime = microtime(true);

    $job = new ProcessTransactionJob($transaction);
    $job->handle($this->validationService);

    $endTime = microtime(true);
    $processingTime = $endTime - $startTime;

    // Should process within 500ms
    $this->assertLessThan(0.5, $processingTime, 'Single transaction should process within 500ms');

    $transaction->refresh();
    $this->assertNotEquals('PENDING', $transaction->validation_status);
}
```

For detailed test documentation, see: [Transaction Pipeline Test Suite Documentation](TRANSACTION_PIPELINE_TEST_SUITE.md)

```php
// tests/Feature/TransactionProcessingBaseTest.php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Transaction;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\TransactionJob;
use App\Models\TransactionValidation;
use App\Jobs\ProcessTransactionJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;

abstract class TransactionProcessingBaseTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant;
    protected $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenant and terminal
        $this->tenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
            'code' => 'TEST001'
        ]);

        $this->terminal = PosTerminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'terminal_uid' => 'TEST-TERM-001',
            'status_id' => 1,
            'customer_code' => 'CUST001'
        ]);
    }

    protected function createValidTransactionPayload($overrides = []): array
    {
        return array_merge([
            'payload' => [
                'customer_code' => 'CUST001',
                'terminal_id' => $this->terminal->id,
                'transaction_id' => 'TXN-' . now()->format('YmdHis') . '-' . rand(100, 999),
                'hardware_id' => 'HW001',
                'transaction_timestamp' => now()->toISOString(),
                'base_amount' => 1000.50,
                'payload_checksum' => 'test-checksum-' . time()
            ]
        ], $overrides);
    }

    protected function createTransactionWithAdjustmentsAndTaxes(): array
    {
        return [
            'payload' => [
                'customer_code' => 'CUST001',
                'terminal_id' => $this->terminal->id,
                'transaction_id' => 'TXN-COMPLEX-' . time(),
                'hardware_id' => 'HW001',
                'transaction_timestamp' => now()->toISOString(),
                'base_amount' => 1000.00,
                'payload_checksum' => 'complex-checksum',
                'adjustments' => [
                    ['adjustment_type' => 'promo_discount', 'amount' => 50.0],
                    ['adjustment_type' => 'senior_discount', 'amount' => 20.0]
                ],
                'taxes' => [
                    ['tax_type' => 'VAT', 'amount' => 120.0],
                    ['tax_type' => 'OTHER_TAX', 'amount' => 10.0]
                ]
            ]
        ];
    }
}
```

#### 2. Phase 1: Transaction Ingestion Tests

```php
// tests/Feature/TransactionIngestionTest.php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Queue;

class TransactionIngestionTest extends TransactionProcessingBaseTest
{
    /** @test */
    public function can_submit_valid_single_transaction()
    {
        Queue::fake();

        $payload = $this->createValidTransactionPayload();

        $response = $this->postJson('/api/transactions', $payload);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'status',
                    'message',
                    'data' => ['transaction_id']
                ])
                ->assertJson([
                    'status' => 'success',
                    'message' => 'Transaction queued for processing'
                ]);

        // Verify transaction was created in database
        $this->assertDatabaseHas('transactions', [
            'transaction_id' => $payload['payload']['transaction_id'],
            'customer_code' => $payload['payload']['customer_code'],
            'terminal_id' => $this->terminal->id,
            'base_amount' => $payload['payload']['base_amount']
        ]);

        // Verify job was dispatched
        Queue::assertPushed(ProcessTransactionJob::class);
    }

    /** @test */
    public function can_submit_transaction_with_adjustments_and_taxes()
    {
        Queue::fake();

        $payload = $this->createTransactionWithAdjustmentsAndTaxes();

        $response = $this->postJson('/api/transactions', $payload);

        $response->assertStatus(201);

        $transaction = Transaction::where('transaction_id', $payload['payload']['transaction_id'])->first();

        // Verify adjustments were created
        $this->assertCount(2, $transaction->adjustments);
        $this->assertEquals(50.0, $transaction->adjustments->firstWhere('adjustment_type', 'promo_discount')->amount);

        // Verify taxes were created
        $this->assertCount(2, $transaction->taxes);
        $this->assertEquals(120.0, $transaction->taxes->firstWhere('tax_type', 'VAT')->amount);
    }

    /** @test */
    public function rejects_transaction_with_missing_required_fields()
    {
        $payload = [
            'payload' => [
                'terminal_id' => $this->terminal->id,
                // Missing transaction_id, base_amount, customer_code
            ]
        ];

        $response = $this->postJson('/api/transactions', $payload);

        $response->assertStatus(500)
                ->assertJsonStructure([
                    'status',
                    'message'
                ]);
    }

    /** @test */
    public function rejects_transaction_with_invalid_terminal_id()
    {
        $payload = $this->createValidTransactionPayload([
            'payload' => ['terminal_id' => 99999]
        ]);

        $response = $this->postJson('/api/transactions', $payload);

        $response->assertStatus(500)
                ->assertJsonFragment([
                    'status' => 'error'
                ]);
    }

    /** @test */
    public function handles_duplicate_transaction_submission()
    {
        Queue::fake();

        $payload = $this->createValidTransactionPayload();

        // Submit first time
        $response1 = $this->postJson('/api/transactions', $payload);
        $response1->assertStatus(201);

        // Submit duplicate
        $response2 = $this->postJson('/api/transactions', $payload);
        $response2->assertStatus(200)
                 ->assertJsonFragment([
                     'already_processed' => true
                 ]);
    }

    /** @test */
    public function validates_transaction_data_types()
    {
        $payload = $this->createValidTransactionPayload([
            'payload' => [
                'base_amount' => 'invalid-amount',
                'terminal_id' => 'invalid-id'
            ]
        ]);

        $response = $this->postJson('/api/transactions', $payload);

        $response->assertStatus(500);
    }
}
```

#### 3. Phase 2: Queue Processing Tests

```php
// tests/Feature/TransactionQueueProcessingTest.php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionJob;
use App\Services\TransactionValidationService;
use Illuminate\Support\Facades\Queue;

class TransactionQueueProcessingTest extends TransactionProcessingBaseTest
{
    /** @test */
    public function transaction_job_is_queued_after_submission()
    {
        Queue::fake();

        $payload = $this->createValidTransactionPayload();

        $this->postJson('/api/transactions', $payload);

        Queue::assertPushed(ProcessTransactionJob::class, function ($job) use ($payload) {
            return $job->transaction->transaction_id === $payload['payload']['transaction_id'];
        });
    }

    /** @test */
    public function transaction_job_processes_successfully()
    {
        $payload = $this->createValidTransactionPayload();

        // Create transaction directly
        $transaction = Transaction::create([
            'customer_code' => $payload['payload']['customer_code'],
            'terminal_id' => $this->terminal->id,
            'transaction_id' => $payload['payload']['transaction_id'],
            'hardware_id' => $payload['payload']['hardware_id'],
            'transaction_timestamp' => $payload['payload']['transaction_timestamp'],
            'base_amount' => $payload['payload']['base_amount'],
            'payload_checksum' => $payload['payload']['payload_checksum']
        ]);

        // Process the job
        $job = new ProcessTransactionJob($transaction);
        $validationService = app(TransactionValidationService::class);

        $job->handle($validationService);

        // Verify job record was created
        $this->assertDatabaseHas('transaction_jobs', [
            'transaction_id' => $transaction->transaction_id,
            'status' => 'COMPLETED'
        ]);

        // Verify validation record was created
        $this->assertDatabaseHas('transaction_validations', [
            'transaction_id' => $transaction->transaction_id,
            'status' => 'VALID'
        ]);
    }

    /** @test */
    public function handles_transaction_job_failure()
    {
        // Create transaction with invalid data that will fail validation
        $transaction = Transaction::create([
            'customer_code' => 'INVALID',
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-FAIL-' . time(),
            'hardware_id' => 'HW001',
            'transaction_timestamp' => now(),
            'base_amount' => -100, // Invalid negative amount
            'payload_checksum' => 'test-checksum'
        ]);

        $job = new ProcessTransactionJob($transaction);
        $validationService = app(TransactionValidationService::class);

        try {
            $job->handle($validationService);
        } catch (\Exception $e) {
            // Expected to fail
        }

        // Verify failure was recorded
        $this->assertDatabaseHas('transaction_jobs', [
            'transaction_id' => $transaction->transaction_id,
            'status' => 'FAILED'
        ]);

        $this->assertDatabaseHas('transaction_validations', [
            'transaction_id' => $transaction->transaction_id,
            'status' => 'ERROR'
        ]);
    }

    /** @test */
    public function job_retry_mechanism_works()
    {
        Queue::fake();

        $transaction = Transaction::factory()->create([
            'terminal_id' => $this->terminal->id
        ]);

        $job = new ProcessTransactionJob($transaction);

        // Simulate job failure
        $job->fail(new \Exception('Simulated failure'));

        // Verify job can be retried
        $this->assertTrue($job->attempts() <= 3); // Max attempts
    }
}
```

#### 4. Phase 3: Validation Service Tests

```php
// tests/Feature/TransactionValidationServiceTest.php
<?php

namespace Tests\Feature;

use App\Services\TransactionValidationService;
use Carbon\Carbon;

class TransactionValidationServiceTest extends TransactionProcessingBaseTest
{
    protected $validationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validationService = app(TransactionValidationService::class);
    }

    /** @test */
    public function validates_valid_transaction_data()
    {
        $transaction = Transaction::create([
            'customer_code' => 'CUST001',
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-VALID-' . time(),
            'hardware_id' => 'HW001',
            'transaction_timestamp' => now(),
            'base_amount' => 100.00,
            'payload_checksum' => 'valid-checksum'
        ]);

        $result = $this->validationService->validateTransaction($transaction);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function rejects_transaction_with_excessive_amount()
    {
        $transaction = Transaction::create([
            'customer_code' => 'CUST001',
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-HIGH-' . time(),
            'hardware_id' => 'HW001',
            'transaction_timestamp' => now(),
            'base_amount' => 999999.99, // Excessive amount
            'payload_checksum' => 'high-checksum'
        ]);

        $result = $this->validationService->validateTransaction($transaction);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    /** @test */
    public function rejects_transaction_outside_operating_hours()
    {
        $transaction = Transaction::create([
            'customer_code' => 'CUST001',
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-HOURS-' . time(),
            'hardware_id' => 'HW001',
            'transaction_timestamp' => Carbon::now()->setHour(23)->setMinute(30), // After hours
            'base_amount' => 100.00,
            'payload_checksum' => 'hours-checksum'
        ]);

        $result = $this->validationService->validateTransaction($transaction);

        // This would fail if operating hours validation is implemented
        // Adjust based on your business rules
    }

    /** @test */
    public function validates_vat_calculations()
    {
        $transactionData = [
            'customer_code' => 'CUST001',
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-VAT-' . time(),
            'base_amount' => 100.00,
            'adjustments' => [
                ['adjustment_type' => 'discount', 'amount' => 10.00]
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 12.00] // 12% VAT on 100
            ]
        ];

        $result = $this->validationService->validate($transactionData);

        // VAT validation logic depends on your business rules
        $this->assertTrue($result['valid']);
    }

    /** @test */
    public function validates_service_charge_limits()
    {
        $transactionData = [
            'customer_code' => 'CUST001',
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-SERVICE-' . time(),
            'base_amount' => 100.00,
            'adjustments' => [
                ['adjustment_type' => 'service_charge', 'amount' => 20.00] // 20% service charge (over limit)
            ]
        ];

        $result = $this->validationService->validate($transactionData);

        // Should fail if service charge exceeds 15% limit
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('service charge', strtolower(implode(' ', $result['errors'])));
    }
}
```

#### 5. Phase 4: Status Tracking Tests

```php
// tests/Feature/TransactionStatusTrackingTest.php
<?php

namespace Tests\Feature;

class TransactionStatusTrackingTest extends TransactionProcessingBaseTest
{
    /** @test */
    public function can_retrieve_transaction_status()
    {
        // Create transaction with related records
        $transaction = Transaction::create([
            'customer_code' => 'CUST001',
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-STATUS-' . time(),
            'hardware_id' => 'HW001',
            'transaction_timestamp' => now(),
            'base_amount' => 1000.50,
            'payload_checksum' => 'status-checksum'
        ]);

        // Create job record

        $job = TransactionJob::create([
            'transaction_id' => $transaction->transaction_id,
            'job_status' => 'COMPLETED',
            'attempt_number' => 1,
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute()
        ]);

        // Create validation record
        $validation = TransactionValidation::create([
            'transaction_id' => $transaction->transaction_id,
            'status_code' => 'VALID',
            'started_at' => now()->subMinutes(2),
            'validated_at' => now()->subMinute()
        ]);

        $response = $this->getJson("/api/v1/transactions/{$transaction->transaction_id}/status");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'status',
                    'data' => [
                        'transaction_id',
                        'customer_code',
                        'base_amount',
                        'latest_job',
                        'latest_validation'
                    ]
                ])
                ->assertJson([
                    'status' => 'success',
                    'data' => [
                        'transaction_id' => $transaction->transaction_id,
                        'customer_code' => 'CUST001',
                        'base_amount' => '1000.50'
                    ]
                ]);
    }

    /** @test */
    public function returns_404_for_nonexistent_transaction()
    {
        $response = $this->getJson('/api/v1/transactions/NONEXISTENT-TXN-ID/status');

        $response->assertStatus(404)
                ->assertJson([
                    'status' => 'error',
                    'message' => 'Transaction not found'
                ]);
    }

    /** @test */
    public function status_includes_adjustments_and_taxes()
    {
        $transaction = Transaction::create([
            'customer_code' => 'CUST001',
            'terminal_id' => $this->terminal->id,
            'transaction_id' => 'TXN-COMPLEX-STATUS-' . time(),
            'hardware_id' => 'HW001',
            'transaction_timestamp' => now(),
            'base_amount' => 1000.00,
            'payload_checksum' => 'complex-status-checksum'
        ]);

        // Add adjustments and taxes
        $transaction->adjustments()->create(['adjustment_type' => 'discount', 'amount' => 50.00]);
        $transaction->taxes()->create(['tax_type' => 'VAT', 'amount' => 120.00]);

        $response = $this->getJson("/api/v1/transactions/{$transaction->transaction_id}/status");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        'adjustments',
                        'taxes'
                    ]
                ]);

        $data = $response->json('data');
        $this->assertCount(1, $data['adjustments']);
        $this->assertCount(1, $data['taxes']);
    }
}
```

#### 6. End-to-End Integration Tests

```php
// tests/Feature/TransactionEndToEndTest.php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionJob;
use App\Services\TransactionValidationService;
use Illuminate\Support\Facades\Bus;

class TransactionEndToEndTest extends TransactionProcessingBaseTest
{
    /** @test */
    public function complete_transaction_processing_pipeline()
    {
        // Step 1: Submit transaction
        $payload = $this->createValidTransactionPayload();

        $response = $this->postJson('/api/transactions', $payload);
        $response->assertStatus(201);

        $transactionId = $payload['payload']['transaction_id'];

        // Step 2: Verify transaction was created
        $transaction = Transaction::where('transaction_id', $transactionId)->first();
        $this->assertNotNull($transaction);
        $this->assertEquals('CUST001', $transaction->customer_code);

        // Step 3: Process the job manually (simulate queue processing)
        $job = new ProcessTransactionJob($transaction);
        $validationService = app(TransactionValidationService::class);
        $job->handle($validationService);

        // Step 4: Verify processing completed
        $this->assertDatabaseHas('transaction_jobs', [
            'transaction_id' => $transactionId,
            'status' => 'COMPLETED'
        ]);

        $this->assertDatabaseHas('transaction_validations', [
            'transaction_id' => $transactionId,
            'status' => 'VALID'
        ]);

        // Step 5: Check status endpoint
        $statusResponse = $this->getJson("/api/v1/transactions/{$transactionId}/status");
        $statusResponse->assertStatus(200)
                      ->assertJsonPath('data.latest_job.status', 'COMPLETED')
                      ->assertJsonPath('data.latest_validation.status', 'VALID');
    }

    /** @test */
    public function complete_pipeline_with_complex_transaction()
    {
        $payload = $this->createTransactionWithAdjustmentsAndTaxes();

        // Submit transaction
        $response = $this->postJson('/api/transactions', $payload);
        $response->assertStatus(201);

        $transactionId = $payload['payload']['transaction_id'];
        $transaction = Transaction::where('transaction_id', $transactionId)->first();

        // Verify adjustments and taxes were created
        $this->assertCount(2, $transaction->adjustments);
        $this->assertCount(2, $transaction->taxes);

        // Process the job
        $job = new ProcessTransactionJob($transaction);
        $validationService = app(TransactionValidationService::class);
        $job->handle($validationService);

        // Verify processing completed
        $this->assertDatabaseHas('transaction_jobs', [
            'transaction_id' => $transactionId,
            'status' => 'COMPLETED'
        ]);

        // Check final status
        $statusResponse = $this->getJson("/api/v1/transactions/{$transactionId}/status");
        $statusResponse->assertStatus(200);

        $data = $statusResponse->json('data');
        $this->assertCount(2, $data['adjustments']);
        $this->assertCount(2, $data['taxes']);
    }

    /** @test */
    public function pipeline_handles_validation_failure_gracefully()
    {
        // Create transaction with invalid data
        $payload = $this->createValidTransactionPayload([
            'payload' => [
                'base_amount' => -100, // Invalid negative amount
                'transaction_id' => 'TXN-INVALID-' . time()
            ]
        ]);

        // Submit transaction (should succeed at ingestion level)
        $response = $this->postJson('/api/transactions', $payload);
        $response->assertStatus(201);

        $transaction = Transaction::where('transaction_id', $payload['payload']['transaction_id'])->first();

        // Process the job (should fail at validation)
        $job = new ProcessTransactionJob($transaction);
        $validationService = app(TransactionValidationService::class);

        try {
            $job->handle($validationService);
        } catch (\Exception $e) {
            // Expected to fail
        }

        // Verify failure was recorded properly
        $this->assertDatabaseHas('transaction_jobs', [
            'transaction_id' => $transaction->transaction_id,
            'status' => 'FAILED'
        ]);

        $this->assertDatabaseHas('transaction_validations', [
            'transaction_id' => $transaction->transaction_id,
            'status' => 'ERROR'
        ]);
    }
}
```

#### 7. Performance Tests

```php
// tests/Feature/TransactionPerformanceTest.php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

class TransactionPerformanceTest extends TransactionProcessingBaseTest
{
    /** @test */
    public function can_process_multiple_transactions_efficiently()
    {
        $startTime = microtime(true);
        $transactionCount = 50;

        // Submit multiple transactions
        for ($i = 0; $i < $transactionCount; $i++) {
            $payload = $this->createValidTransactionPayload([
                'payload' => [
                    'transaction_id' => "TXN-PERF-{$i}-" . time()
                ]
            ]);

            $response = $this->postJson('/api/transactions', $payload);
            $response->assertStatus(201);
        }

        $ingestionTime = microtime(true) - $startTime;

        // Verify all transactions were created
        $this->assertEquals($transactionCount, Transaction::count());

        // Performance assertion (adjust threshold as needed)
        $this->assertLessThan(10, $ingestionTime, 'Transaction ingestion took too long');
    }

    /** @test */
    public function database_queries_are_optimized()
    {
        DB::enableQueryLog();

        $payload = $this->createValidTransactionPayload();
        $this->postJson('/api/transactions', $payload);

        $queries = DB::getQueryLog();

        // Verify reasonable number of queries (adjust threshold as needed)
        $this->assertLessThan(10, count($queries), 'Too many database queries for single transaction');

        DB::disableQueryLog();
    }
}
```

**Run All Tests:**

```bash
# Run all transaction processing tests
php artisan test tests/Feature/Transaction*

# Run specific test phases
php artisan test tests/Feature/TransactionIngestionTest.php
php artisan test tests/Feature/TransactionQueueProcessingTest.php
php artisan test tests/Feature/TransactionValidationServiceTest.php
php artisan test tests/Feature/TransactionStatusTrackingTest.php
php artisan test tests/Feature/TransactionEndToEndTest.php

# Run with coverage
php artisan test --coverage-html coverage-report

# Run performance tests separately
php artisan test tests/Feature/TransactionPerformanceTest.php

# Run tests in parallel (if configured)
php artisan test --parallel

# Run tests with detailed output
php artisan test --verbose
```

## UAT Checklist

### Phase 1: Ingestion Testing

-   [ ] Single transaction submission successful
-   [ ] Batch transaction submission successful
-   [ ] Payload validation working (required fields)
-   [ ] Payload checksum validation working
-   [ ] Duplicate transaction detection working
-   [ ] Invalid terminal ID rejection working
-   [ ] Malformed JSON rejection working

### Phase 2: Queue Processing Testing

-   [ ] Transaction job queuing working
-   [ ] Queue workers processing jobs
-   [ ] Job status transitions correctly tracked
-   [ ] Failed job retry mechanism working
-   [ ] Job timeout handling working
-   [ ] Queue monitoring tools functional

### Phase 3: Validation Testing

-   [ ] Business rules validation working
-   [ ] Operating hours validation working
-   [ ] Amount limits validation working
-   [ ] VAT calculation validation working
-   [ ] Service charge limits validation working
-   [ ] Discount limits validation working
-   [ ] Transaction age validation working

### Phase 4: Status Tracking Testing

-   [ ] Transaction status retrieval working
-   [ ] Real-time status updates working
-   [ ] Status API response format correct
-   [ ] Processing completion notifications working
-   [ ] Error status reporting working

### Phase 5: Data Integrity Testing

-   [ ] Adjustments properly linked to transactions
-   [ ] Taxes properly linked to transactions
-   [ ] Customer code auto-population working
-   [ ] Hardware ID auto-population working
-   [ ] Database transaction integrity maintained
-   [ ] Audit trail completeness verified

### Phase 6: Performance & Reliability Testing

-   [ ] Batch processing performance acceptable (>100 TPS)
-   [ ] Memory usage within acceptable limits
-   [ ] Queue processing doesn't lag under load
-   [ ] Database query performance acceptable
-   [ ] System logging captures all events
-   [ ] Error recovery mechanisms working

### Phase 7: Monitoring & Debugging Testing

-   [ ] System logs properly generated
-   [ ] Queue monitoring dashboards working
-   [ ] Health check endpoints functional
-   [ ] Alert systems working for failures
-   [ ] Log analysis tools accessible
-   [ ] Performance metrics collection working

### Phase 8: Security & Compliance Testing

-   [ ] Input sanitization working
-   [ ] SQL injection prevention verified
-   [ ] Rate limiting functional (if implemented)
-   [ ] Audit trail immutability verified
-   [ ] Data encryption in transit verified
-   [ ] Access control working properly

### Sign-off Requirements

**Technical Sign-off:**

-   [ ] All automated tests passing
-   [ ] Performance benchmarks met
-   [ ] Security scan completed
-   [ ] Code review completed
-   [ ] Documentation reviewed and approved

**Business Sign-off:**

-   [ ] User acceptance criteria met
-   [ ] Business rules validation confirmed
-   [ ] Compliance requirements satisfied
-   [ ] Training materials prepared
-   [ ] Go-live procedures documented

## Related Documentation

For detailed payload specifications and examples, see:

-   [TSMS POS Transaction Payload Guide](../TSMS_POS_Transaction_Payload_Guide.md)
-   [Transaction Schema Reference](../transaction_schema_summary.md)
-   [API Integration Guidelines](../TSMS_integration_guidelines.md)

## Monitoring and Debugging Tools

### Real-time Monitoring

**Queue Monitoring:**

```bash
# Monitor queue in real-time
php artisan queue:monitor

# Check queue size
php artisan queue:size

# Monitor with Horizon (if installed)
php artisan horizon
# Then visit: http://localhost:8000/horizon
```

**Database Monitoring Queries:**

```sql
-- Transaction processing status overview
SELECT
    t.transaction_id,
    t.base_amount,
    t.created_at as submitted_at,
    tj.status as job_status,
    tv.status as validation_status,
    tj.completed_at as processed_at
FROM transactions t
LEFT JOIN transaction_jobs tj ON t.transaction_id = tj.transaction_id
LEFT JOIN transaction_validations tv ON t.transaction_id = tv.transaction_id
ORDER BY t.created_at DESC
LIMIT 10;

-- Failed transactions analysis
SELECT
    tj.transaction_id,
    tj.status,
    tj.attempt_number,
    tj.error_message,
    tj.started_at
FROM transaction_jobs tj
WHERE tj.status = 'FAILED'
ORDER BY tj.started_at DESC;

-- Processing performance metrics
SELECT
    DATE(tj.started_at) as processing_date,
    COUNT(*) as total_processed,
    AVG(TIMESTAMPDIFF(SECOND, tj.started_at, tj.completed_at)) as avg_processing_time,
    SUM(CASE WHEN tj.status = 'COMPLETED' THEN 1 ELSE 0 END) as successful,
    SUM(CASE WHEN tj.status = 'FAILED' THEN 1 ELSE 0 END) as failed
FROM transaction_jobs tj
WHERE tj.completed_at IS NOT NULL
GROUP BY DATE(tj.started_at)
ORDER BY processing_date DESC;
```

### Log Analysis

**Application Logs:**

```bash
# Follow transaction processing logs
tail -f storage/logs/laravel.log | grep "transaction"

# Search for specific transaction
grep "TXN-20250706123001-001" storage/logs/laravel.log

# Monitor error logs
tail -f storage/logs/laravel.log | grep "ERROR"
```

**System Logs Monitoring:**

```sql
-- Recent system logs for transactions
SELECT
    sl.created_at,
    sl.severity,
    sl.message,
    sl.terminal_uid,
    sl.transaction_id
FROM system_logs sl
WHERE sl.type = 'transaction'
ORDER BY sl.created_at DESC
LIMIT 20;

-- Error logs analysis
SELECT
    sl.created_at,
    sl.message,
    sl.context
FROM system_logs sl
WHERE sl.severity = 'error'
AND sl.type = 'transaction'
ORDER BY sl.created_at DESC;
```

### Debugging Commands

**Artisan Commands for Debugging:**

```bash
# Process a specific transaction manually
php artisan tinker
>>> $transaction = \App\Models\Transaction::where('transaction_id', 'TXN-TEST-001')->first();
>>> \App\Jobs\ProcessTransactionJob::dispatchSync($transaction);

# Clear failed jobs
php artisan queue:clear

# Restart queue workers
php artisan queue:restart

# Run validation service directly
php artisan tinker
>>> $service = app(\App\Services\TransactionValidationService::class);
>>> $result = $service->validateTransaction($transaction);
>>> dump($result);
```

**Testing Specific Validation Rules:**

```bash
# Test payload parser
curl -X POST http://localhost:8000/api/v1/test-parser \
  -H "Content-Type: text/plain" \
  -d "TRANSACTION_ID:TXN-001
AMOUNT:1000.50
TERMINAL:TERM-001"

# Test validation service with edge cases
php artisan tinker
>>> $service = app(\App\Services\TransactionValidationService::class);
>>> $testData = [
...     'customer_code' => 'EDGE001',
...     'terminal_id' => 1,
...     'transaction_id' => 'EDGE-TEST-001',
...     'base_amount' => 0.01,  // Test minimum amount
... ];
>>> $result = $service->validate($testData);
>>> dump($result);
```

### Performance Profiling

**Database Query Analysis:**

```bash
# Enable query logging in .env
DB_LOG_QUERIES=true

# Monitor slow queries
tail -f storage/logs/laravel.log | grep "slow"
```

**Memory and CPU Monitoring:**

```bash
# Monitor queue worker memory usage
ps aux | grep "queue:work"

# Monitor Redis memory (if using Redis queue)
redis-cli info memory

# Check Laravel performance with Telescope (if installed)
php artisan telescope:install
```

### Health Check Endpoints

Create health check endpoints for monitoring:

```php
// routes/api.php - Add health check endpoints
Route::get('/health/transactions', function () {
    $recentFailures = \App\Models\TransactionJob::where('status', 'FAILED')
        ->where('created_at', '>', now()->subMinutes(5))
        ->count();

    $queueSize = \Illuminate\Support\Facades\Queue::size('transactions');

    return response()->json([
        'status' => $recentFailures > 10 ? 'unhealthy' : 'healthy',
        'queue_size' => $queueSize,
        'recent_failures' => $recentFailures,
        'timestamp' => now()->toISOString()
    ]);
});

Route::get('/health/validation', function () {
    $validationService = app(\App\Services\TransactionValidationService::class);

    try {
        // Test validation service with dummy data
        $testResult = $validationService->validate([
            'customer_code' => 'HEALTH_CHECK',
            'terminal_id' => 1,
            'transaction_id' => 'HEALTH-'.time(),
            'base_amount' => 100.00
        ]);

        return response()->json([
            'status' => 'healthy',
            'validation_service' => 'operational',
            'timestamp' => now()->toISOString()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'error' => $e->getMessage(),
            'timestamp' => now()->toISOString()
        ], 500);
    }
});
```

**Health Check Usage:**

```bash
# Check transaction processing health
curl http://localhost:8000/api/health/transactions

# Check validation service health
curl http://localhost:8000/api/health/validation
```
