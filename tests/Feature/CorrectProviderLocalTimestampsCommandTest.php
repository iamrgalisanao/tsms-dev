<?php

namespace Tests\Feature;

use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Backfill\ConnectionIdentityResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CorrectProviderLocalTimestampsCommandTest extends TestCase
{
    public function test_it_skips_duplicate_receipt_date_conflicts_when_requested(): void
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            $this->markTestSkipped('transactions.original_payload is not available.');
        }

        $tenant = Tenant::factory()->create();
        $provider = PosProvider::factory()->create([
            'timezone' => 'Asia/Manila',
            'timestamp_mode' => 'local_time_with_z',
        ]);
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'provider_id' => $provider->id,
        ]);

        Transaction::query()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'receipt_no' => 'PH0128110675',
            'transaction_timestamp' => '2026-07-21 17:10:49',
            'gross_sales' => 115.00,
            'net_sales' => 115.00,
            'customer_code' => 'TEST001',
            'payload_checksum' => str_repeat('a', 64),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'original_payload' => json_encode([
                'transaction_timestamp' => '2026-07-22T01:10:49Z',
                'receipt_no' => 'PH0128110675',
            ]),
        ]);

        $conflicting = Transaction::query()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'receipt_no' => 'PH0128110675',
            'transaction_timestamp' => '2026-07-22 01:10:49',
            'submission_timestamp' => '2026-07-22 02:13:30',
            'gross_sales' => 115.00,
            'net_sales' => 115.00,
            'customer_code' => 'TEST001',
            'payload_checksum' => str_repeat('b', 64),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'original_payload' => json_encode([
                'transaction_timestamp' => '2026-07-22T01:10:49Z',
                'receipt_no' => 'PH0128110675',
            ]),
        ]);

        $clean = Transaction::query()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'receipt_no' => 'PH0128110676',
            'transaction_timestamp' => '2026-07-22 01:17:28',
            'submission_timestamp' => '2026-07-22 02:17:28',
            'gross_sales' => 105.00,
            'net_sales' => 105.00,
            'customer_code' => 'TEST001',
            'payload_checksum' => str_repeat('c', 64),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'original_payload' => json_encode([
                'transaction_timestamp' => '2026-07-22T01:17:28Z',
                'receipt_no' => 'PH0128110676',
            ]),
        ]);

        $this->artisan('tsms:correct-provider-local-timestamps', [
            '--tenant' => $tenant->id,
            '--from' => '2026-07-21 00:00:00',
            '--to' => '2026-07-22 23:59:59',
            '--timezone' => 'Asia/Manila',
            '--apply' => true,
            '--skip-duplicates' => true,
        ])
            ->expectsOutput('Duplicate conflicts skipped: 1')
            ->assertExitCode(0);

        $this->assertSame(
            '2026-07-22 01:10:49',
            Carbon::parse($conflicting->fresh()->transaction_timestamp)->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2026-07-21 17:17:28',
            Carbon::parse($clean->fresh()->transaction_timestamp)->format('Y-m-d H:i:s')
        );
    }

    /**
     * Slice 18 (T077) follow-up: reports:refresh-daily-transaction-summaries
     * can now genuinely refuse (connection-identity mismatch). This command
     * still corrects rows and exits 0 in that case (the correction itself
     * succeeded), but must surface the refresh failure rather than silently
     * implying summaries were refreshed.
     */
    public function test_it_surfaces_an_error_but_still_succeeds_when_summary_refresh_fails(): void
    {
        if (! Schema::hasColumn('transactions', 'original_payload')) {
            $this->markTestSkipped('transactions.original_payload is not available.');
        }

        $tenant = Tenant::factory()->create();
        $provider = PosProvider::factory()->create([
            'timezone' => 'Asia/Manila',
            'timestamp_mode' => 'local_time_with_z',
        ]);
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'provider_id' => $provider->id,
        ]);

        Transaction::query()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'hardware_id' => $terminal->serial_number,
            'receipt_no' => 'PH0128110677',
            'transaction_timestamp' => '2026-07-22 01:20:00',
            'gross_sales' => 115.00,
            'net_sales' => 115.00,
            'customer_code' => 'TEST001',
            'payload_checksum' => str_repeat('d', 64),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
            'original_payload' => json_encode([
                'transaction_timestamp' => '2026-07-22T01:20:00Z',
                'receipt_no' => 'PH0128110677',
            ]),
        ]);

        $this->app->instance(ConnectionIdentityResolver::class, new class implements ConnectionIdentityResolver
        {
            private int $calls = 0;

            public function resolve(string $connectionName): array
            {
                $this->calls++;

                return $this->calls === 1
                    ? ['server_id' => 999, 'database' => 'wrong_db']
                    : ['server_id' => 1, 'database' => 'primary_db'];
            }
        });

        $this->artisan('tsms:correct-provider-local-timestamps', [
            '--tenant' => $tenant->id,
            '--from' => '2026-07-21 00:00:00',
            '--to' => '2026-07-22 23:59:59',
            '--timezone' => 'Asia/Manila',
            '--apply' => true,
            '--refresh-summaries' => true,
        ])
            ->expectsOutputToContain('Daily summary refresh failed')
            ->assertExitCode(0);
    }
}
