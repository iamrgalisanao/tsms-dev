<?php

namespace Tests\Feature;

use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionReceiptNumberBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_receipt_number_from_original_payload(): void
    {
        if (! Schema::hasColumn('transactions', 'receipt_no') || ! Schema::hasColumn('transactions', 'original_payload')) {
            $this->markTestSkipped('receipt_no or original_payload column is not available.');
        }

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $receiptNo = '0100038064';

        $transaction = Transaction::query()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'transaction_timestamp' => now(),
            'gross_sales' => 739.00,
            'net_sales' => 739.00,
            'customer_code' => 'C-01003',
            'payload_checksum' => str_repeat('a', 64),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'receipt_no' => null,
            'original_payload' => json_encode([
                'transaction_id' => (string) Str::uuid(),
                'receipt_no' => $receiptNo,
            ]),
        ]);

        $migration = include database_path('migrations/2026_06_14_000003_backfill_transaction_receipt_numbers.php');
        $migration->up();

        $this->assertSame($receiptNo, $transaction->fresh()->receipt_no);
    }

    public function test_backfill_skips_receipt_number_when_it_would_duplicate_existing_receipt_for_day(): void
    {
        if (! Schema::hasColumn('transactions', 'receipt_no') || ! Schema::hasColumn('transactions', 'original_payload')) {
            $this->markTestSkipped('receipt_no or original_payload column is not available.');
        }

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $receiptNo = '1572';
        $timestamp = now()->setDate(2026, 6, 13);

        Transaction::query()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'transaction_timestamp' => $timestamp->copy()->setTime(8, 30),
            'gross_sales' => 100.00,
            'net_sales' => 100.00,
            'customer_code' => 'C-01003',
            'payload_checksum' => str_repeat('b', 64),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'receipt_no' => $receiptNo,
            'original_payload' => json_encode(['receipt_no' => $receiptNo]),
        ]);

        $duplicate = Transaction::query()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'transaction_timestamp' => $timestamp->copy()->setTime(12, 15),
            'gross_sales' => 739.00,
            'net_sales' => 739.00,
            'customer_code' => 'C-01003',
            'payload_checksum' => str_repeat('c', 64),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'receipt_no' => null,
            'original_payload' => json_encode(['receipt_no' => $receiptNo]),
        ]);

        $migration = include database_path('migrations/2026_06_14_000003_backfill_transaction_receipt_numbers.php');
        $migration->up();

        $this->assertNull($duplicate->fresh()->receipt_no);
    }
}
