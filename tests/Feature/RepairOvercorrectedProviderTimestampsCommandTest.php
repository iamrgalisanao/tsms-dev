<?php

namespace Tests\Feature;

use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class RepairOvercorrectedProviderTimestampsCommandTest extends TestCase
{
    public function test_it_repairs_only_rows_shifted_by_one_extra_provider_offset(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = PosProvider::factory()->create([
            'timezone' => 'Asia/Manila',
            'timestamp_mode' => 'local_time_with_z',
        ]);
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'provider_id' => $provider->id,
        ]);

        $submissionUuid = (string) Str::uuid();
        $overcorrected = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'receipt_no' => '000000000034255',
            'transaction_timestamp' => '2026-07-03 13:35:41',
            'submission_timestamp' => '2026-07-03 13:35:50',
            'submission_uuid' => $submissionUuid,
            'original_payload' => json_encode([
                'transaction_timestamp' => '2026-07-04T05:35:41Z',
                'receipt_no' => '000000000034255',
            ]),
        ]);

        $alreadyCorrect = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'receipt_no' => '000000000034256',
            'transaction_timestamp' => '2026-07-03 21:41:38',
            'submission_timestamp' => '2026-07-03 21:41:45',
            'original_payload' => json_encode([
                'transaction_timestamp' => '2026-07-04T05:41:38Z',
                'receipt_no' => '000000000034256',
            ]),
        ]);

        if (Schema::hasTable('transaction_submissions')) {
            DB::table('transaction_submissions')->insert([
                'tenant_id' => $tenant->id,
                'terminal_id' => $terminal->id,
                'submission_uuid' => $submissionUuid,
                'submission_timestamp' => '2026-07-03 13:35:50',
                'transaction_count' => 1,
                'payload_checksum' => str_repeat('a', 64),
                'status' => 'COMPLETED',
                'created_at' => Carbon::parse('2026-07-03 13:35:50'),
                'updated_at' => Carbon::parse('2026-07-03 13:35:50'),
            ]);
        }

        $this->artisan('tsms:repair-overcorrected-provider-timestamps', [
            '--tenant' => $tenant->id,
            '--from' => '2026-07-03 00:00:00',
            '--to' => '2026-07-04 23:59:59',
            '--timezone' => 'Asia/Manila',
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            '2026-07-03 21:35:41',
            Carbon::parse($overcorrected->fresh()->transaction_timestamp)->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2026-07-03 21:35:50',
            Carbon::parse($overcorrected->fresh()->submission_timestamp)->format('Y-m-d H:i:s')
        );

        $this->assertSame(
            '2026-07-03 21:41:38',
            Carbon::parse($alreadyCorrect->fresh()->transaction_timestamp)->format('Y-m-d H:i:s')
        );

        if (Schema::hasTable('transaction_submissions')) {
            $submission = DB::table('transaction_submissions')
                ->where('terminal_id', $terminal->id)
                ->where('submission_uuid', $submissionUuid)
                ->first();

            $this->assertSame('2026-07-03 21:35:50', Carbon::parse($submission->submission_timestamp)->format('Y-m-d H:i:s'));
        }
    }

    public function test_it_repairs_overcorrected_rows_when_payload_timestamp_is_true_utc(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = PosProvider::factory()->create([
            'timezone' => 'Asia/Manila',
            'timestamp_mode' => 'true_utc',
        ]);
        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
            'provider_id' => $provider->id,
        ]);

        $overcorrected = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'receipt_no' => '000000000034255',
            'transaction_timestamp' => '2026-07-03 13:35:41',
            'submission_timestamp' => '2026-07-03 13:35:44',
            'original_payload' => json_encode([
                'transaction_timestamp' => '2026-07-03T21:35:41Z',
                'receipt_no' => '000000000034255',
            ]),
        ]);

        $alreadyCorrect = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'receipt_no' => '000000000034256',
            'transaction_timestamp' => '2026-07-03 21:41:38',
            'submission_timestamp' => '2026-07-03 21:41:45',
            'original_payload' => json_encode([
                'transaction_timestamp' => '2026-07-03T21:41:38Z',
                'receipt_no' => '000000000034256',
            ]),
        ]);

        $this->artisan('tsms:repair-overcorrected-provider-timestamps', [
            '--tenant' => $tenant->id,
            '--from' => '2026-07-03 00:00:00',
            '--to' => '2026-07-04 23:59:59',
            '--timezone' => 'Asia/Manila',
            '--payload-mode' => 'true_utc',
            '--apply' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            '2026-07-03 21:35:41',
            Carbon::parse($overcorrected->fresh()->transaction_timestamp)->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2026-07-03 21:35:44',
            Carbon::parse($overcorrected->fresh()->submission_timestamp)->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2026-07-03 21:41:38',
            Carbon::parse($alreadyCorrect->fresh()->transaction_timestamp)->format('Y-m-d H:i:s')
        );
    }
}
