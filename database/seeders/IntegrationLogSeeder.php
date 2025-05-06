<?php


namespace Database\Seeders;

use App\Models\IntegrationLog;
use App\Models\Tenant;
use App\Models\Transactions;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class IntegrationLogSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::first() ?? Tenant::factory()->create();
        $transaction = Transactions::first() ?? Transactions::factory()->create();

        // Successful integration log
        IntegrationLog::create([
            'tenant_id' => $tenant->id,
            'terminal_id' => 1,
            'transaction_id' => $transaction->transaction_id,
            'request_payload' => [
                'transaction_id' => $transaction->transaction_id,
                'amount' => 1000.00,
                'timestamp' => Carbon::now()->toIso8601String()
            ],
            'response_payload' => [
                'status' => 'SUCCESS',
                'message' => 'Transaction processed successfully'
            ],
            'response_metadata' => [
                'processing_time' => '150ms',
                'server_id' => 'prod-01'
            ],
            'status' => 'SUCCESS',
            'validation_status' => 'PASSED',
            'http_status_code' => 200,
            'response_time' => 150,
            'source_ip' => '192.168.1.100'
        ]);

        // Failed integration log with retry
        IntegrationLog::create([
            'tenant_id' => $tenant->id,
            'terminal_id' => 1,
            'transaction_id' => $transaction->transaction_id,
            'request_payload' => [
                'transaction_id' => $transaction->transaction_id,
                'amount' => 2000.00,
                'timestamp' => Carbon::now()->subMinutes(30)->toIso8601String()
            ],
            'response_payload' => [
                'status' => 'FAILED',
                'message' => 'Gateway timeout'
            ],
            'response_metadata' => [
                'processing_time' => '5000ms',
                'server_id' => 'prod-02'
            ],
            'status' => 'FAILED',
            'validation_status' => 'PASSED',
            'error_message' => 'Gateway timeout after 5000ms',
            'http_status_code' => 504,
            'response_time' => 5000,
            'retry_count' => 2,
            'retry_attempts' => 3,
            'next_retry_at' => Carbon::now()->addMinutes(15),
            'retry_reason' => 'gateway_timeout',
            'source_ip' => '192.168.1.100'
        ]);

        // Invalid request log
        IntegrationLog::create([
            'tenant_id' => $tenant->id,
            'terminal_id' => 1,
            'transaction_id' => $transaction->transaction_id,
            'request_payload' => [
                'transaction_id' => $transaction->transaction_id,
                'amount' => -100.00, // Invalid amount
                'timestamp' => Carbon::now()->subHour()->toIso8601String()
            ],
            'response_payload' => [
                'status' => 'FAILED',
                'message' => 'Invalid amount'
            ],
            'response_metadata' => [
                'processing_time' => '50ms',
                'server_id' => 'prod-01'
            ],
            'status' => 'PERMANENTLY_FAILED',
            'validation_status' => 'FAILED',
            'error_message' => 'Amount cannot be negative',
            'http_status_code' => 400,
            'response_time' => 50,
            'source_ip' => '192.168.1.100'
        ]);
    }
}