<?php

namespace Maatwebsite\Excel\Concerns {
    if (!interface_exists(FromQuery::class)) {
        interface FromQuery {}
    }

    if (!interface_exists(WithMapping::class)) {
        interface WithMapping {}
    }

    if (!interface_exists(WithHeadings::class)) {
        interface WithHeadings {}
    }

    if (!interface_exists(ShouldAutoSize::class)) {
        interface ShouldAutoSize {}
    }

    if (!interface_exists(WithChunkReading::class)) {
        interface WithChunkReading {}
    }
}

namespace Tests\Feature {
    use App\Exports\TransactionLogsExport;
    use App\Models\PosTerminal;
    use App\Models\Tenant;
    use App\Models\Transaction;
    use App\Models\TransactionAdjustment;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Tests\TestCase;

    class TransactionLogsExportQueryTest extends TestCase
    {
        use RefreshDatabase;

        public function test_export_uses_same_tenant_search_as_detailed_table(): void
        {
            $tenant = Tenant::factory()->create(['trade_name' => 'Wendys']);
            $terminal = PosTerminal::factory()->create([
                'tenant_id' => $tenant->id,
                'serial_number' => 'R70866',
                'machine_number' => '1',
            ]);

            Transaction::factory()->create([
                'tenant_id' => $tenant->id,
                'terminal_id' => $terminal->id,
                'transaction_id' => '1a8349bd-52dc-48eb-9015-000000000001',
                'receipt_no' => '17373',
                'gross_sales' => 512.50,
                'net_sales' => 512.50,
                'validation_status' => 'VALID',
                'transaction_timestamp' => '2026-05-25 10:00:00',
                'completed_at' => '2026-05-25 10:01:00',
                'created_at' => '2026-05-25 10:00:00',
            ]);

            Transaction::factory()->create([
                'transaction_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'receipt_no' => '99999',
                'validation_status' => 'VALID',
                'completed_at' => '2026-05-25 11:00:00',
                'created_at' => '2026-05-25 11:00:00',
            ]);

            $export = new TransactionLogsExport([
                'transaction_id' => 'wendys',
                'date_basis' => 'completed',
                'date_from' => '2026-05-25',
                'date_to' => '2026-05-25',
            ]);

            $rows = $export->query()->get();

            $this->assertCount(1, $rows);
            $this->assertSame('17373', $rows->first()->receipt_no);
        }

        public function test_export_matches_terminal_tenant_filter_fallback(): void
        {
            $tenant = Tenant::factory()->create(['trade_name' => 'Wendys']);
            $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);

            Transaction::factory()->create([
                'tenant_id' => Tenant::factory()->create()->id,
                'terminal_id' => $terminal->id,
                'transaction_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'receipt_no' => '17374',
                'validation_status' => 'VALID',
                'completed_at' => '2026-05-25 12:00:00',
                'created_at' => '2026-05-25 12:00:00',
            ]);

            $export = new TransactionLogsExport([
                'tenant_id' => $tenant->id,
                'date_basis' => 'completed',
                'date_from' => '2026-05-25',
                'date_to' => '2026-05-25',
            ]);

            $rows = $export->query()->get();

            $this->assertCount(1, $rows);
            $this->assertSame('17374', $rows->first()->receipt_no);
        }

        public function test_export_maps_canonical_adjustment_rows(): void
        {
            $tenant = Tenant::factory()->create(['trade_name' => 'Subway']);
            $terminal = PosTerminal::factory()->create([
                'tenant_id' => $tenant->id,
                'serial_number' => 'PCAP222919586',
                'machine_number' => '1',
            ]);

            $transaction = Transaction::factory()->create([
                'tenant_id' => $tenant->id,
                'terminal_id' => $terminal->id,
                'transaction_id' => '641feee9-3be5-4215-a85c-614ea2099c5f',
                'receipt_no' => '000001',
                'gross_sales' => 1000,
                'net_sales' => 900,
                'validation_status' => 'VALID',
                'completed_at' => '2026-05-31 12:00:00',
                'created_at' => '2026-05-31 12:00:00',
            ]);

            $transaction->setRelation('terminal', $terminal->setRelation('tenant', $tenant));
            $transaction->setRelation('adjustments', collect([
                new TransactionAdjustment(['adjustment_type' => 'vip_card_discount', 'amount' => 10.00]),
                new TransactionAdjustment(['adjustment_type' => 'employee_discount', 'amount' => 20.00]),
                new TransactionAdjustment(['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 5.00]),
                new TransactionAdjustment(['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 3.00]),
            ]));

            $mapped = (new TransactionLogsExport())->map($transaction);

            $this->assertSame('10.00', $mapped[8]);
            $this->assertSame('20.00', $mapped[9]);
            $this->assertSame('5.00', $mapped[10]);
            $this->assertSame('3.00', $mapped[11]);
        }
    }
}
