<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DuplicateReceiptReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_receipt_report_flags_legacy_payload_conflicts(): void
    {
        if (! Schema::hasColumn('transactions', 'receipt_no') || ! Schema::hasColumn('transactions', 'original_payload')) {
            $this->markTestSkipped('receipt_no or original_payload column is not available.');
        }

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $receiptNo = 'MON-1001';

        $existing = Transaction::query()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'transaction_timestamp' => '2026-06-14 10:00:00',
            'gross_sales' => 100.00,
            'net_sales' => 100.00,
            'customer_code' => 'C-REPORT',
            'payload_checksum' => str_repeat('a', 64),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'receipt_no' => $receiptNo,
            'original_payload' => json_encode(['receipt_no' => $receiptNo]),
        ]);

        $legacy = Transaction::query()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'transaction_timestamp' => '2026-06-14 12:00:00',
            'gross_sales' => 200.00,
            'net_sales' => 200.00,
            'customer_code' => 'C-REPORT',
            'payload_checksum' => str_repeat('b', 64),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'receipt_no' => null,
            'original_payload' => json_encode(['receipt_no' => $receiptNo]),
        ]);

        Artisan::call('tsms:duplicate-receipts', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame([], $payload['duplicate_groups']);
        $this->assertCount(1, $payload['legacy_payload_conflicts']);
        $this->assertSame($legacy->id, $payload['legacy_payload_conflicts'][0]['legacy_transaction_pk']);
        $this->assertSame($existing->id, $payload['legacy_payload_conflicts'][0]['existing_transaction_pk']);
        $this->assertSame($receiptNo, $payload['legacy_payload_conflicts'][0]['receipt_no']);
    }
}
