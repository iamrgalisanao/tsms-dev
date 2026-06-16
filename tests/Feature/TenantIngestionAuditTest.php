<?php

namespace Tests\Feature;

use App\Models\IngestionQuarantine;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIngestionAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_flags_tenant_activity_without_persisted_transactions(): void
    {
        $tenant = Tenant::factory()->create(['trade_name' => 'No Data Tenant']);
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'is_active' => true,
            'status_id' => 1,
        ]);

        TransactionSubmission::query()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'submission_uuid' => (string) Str::uuid(),
            'submission_timestamp' => now(),
            'transaction_count' => 1,
            'payload_checksum' => str_repeat('a', 64),
            'status' => TransactionSubmission::STATUS_RECEIVED,
        ]);

        IngestionQuarantine::query()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'submission_uuid' => (string) Str::uuid(),
            'payload' => '{}',
            'status' => IngestionQuarantine::STATUS_NEW,
            'metadata' => ['reason' => 'CHECKSUM_VALIDATION_FAILED'],
        ]);

        Artisan::call('tsms:ingestion-audit', [
            '--tenant' => $tenant->id,
            '--from' => now()->subDay()->toDateTimeString(),
            '--to' => now()->addDay()->toDateTimeString(),
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $row = $payload['rows'][0];

        $this->assertSame($tenant->id, $row['tenant_id']);
        $this->assertSame(1, $row['submissions']);
        $this->assertSame(1, $row['quarantined']);
        $this->assertSame(0, $row['transactions']);
        $this->assertContains('NO_PERSISTED_TX_WITH_ACTIVITY', $row['flags']);
        $this->assertContains('HAS_QUARANTINE', $row['flags']);
    }

    public function test_it_flags_transactions_persisted_under_a_different_tenant_than_terminal_owner(): void
    {
        $payloadTenant = Tenant::factory()->create(['trade_name' => 'Payload Tenant']);
        $terminalTenant = Tenant::factory()->create(['trade_name' => 'Terminal Tenant']);
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $terminalTenant->id,
            'is_active' => true,
            'status_id' => 1,
        ]);

        $transactionPayload = [
            'tenant_id' => $payloadTenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'transaction_timestamp' => now(),
            'gross_sales' => 1000,
            'net_sales' => 900,
            'customer_code' => 'TEST001',
            'payload_checksum' => str_repeat('b', 64),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
        ];

        if (Schema::hasColumn('transactions', 'base_amount')) {
            $transactionPayload['base_amount'] = 1000;
        }

        Transaction::query()->create($transactionPayload);

        Artisan::call('tsms:ingestion-audit', [
            '--tenant' => $payloadTenant->id,
            '--from' => now()->subDay()->toDateTimeString(),
            '--to' => now()->addDay()->toDateTimeString(),
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $row = $payload['rows'][0];

        $this->assertSame(1, $row['transactions']);
        $this->assertSame(1, $row['tenant_terminal_drift']);
        $this->assertContains('TENANT_TERMINAL_DRIFT', $row['flags']);
    }
}
