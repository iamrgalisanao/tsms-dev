<?php

namespace Tests\Feature;

use App\Jobs\ProcessTransactionIntakeJob;
use App\Jobs\ProcessTransactionJob;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use App\Services\IngestionQueueRouter;
use App\Services\DeadlockRetryService;
use App\Services\TransactionIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ProcessTransactionIntakeJobBatchFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_item_failure_does_not_strand_successful_prior_items_without_stage_two_dispatch(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $submissionUuid = (string) Str::uuid();
        $successfulTransactionId = 'BATCH-SUCCESS-' . Str::upper(Str::random(8));
        $failedTransactionId = 'BATCH-FAILED-' . Str::upper(Str::random(8));

        $intake = TransactionIntake::create([
            'submission_uuid' => $submissionUuid,
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'payload_checksum' => str_repeat('a', 64),
            'payload' => [
                'submission_uuid' => $submissionUuid,
                'submission_timestamp' => now()->format('Y-m-d\TH:i:s\Z'),
                'payload_checksum' => str_repeat('a', 64),
                'transactions' => [
                    $this->transactionPayload($successfulTransactionId, $terminal->serial_number),
                    $this->failingTransactionPayload($failedTransactionId, $terminal->serial_number),
                ],
            ],
            'payload_size_bytes' => 1024,
            'source_ip' => '127.0.0.1',
            'intake_status' => TransactionIntake::INTAKE_STATUS_QUEUED,
            'processing_status' => null,
            'trace_id' => (string) Str::uuid(),
            'received_at' => now(),
            'queued_at' => now(),
        ]);

        (new ProcessTransactionIntakeJob($intake->id))->handle(app(\App\Services\TransactionIngestService::class));

        $successfulTransaction = Transaction::where('transaction_id', $successfulTransactionId)->firstOrFail();

        $queue = app(IngestionQueueRouter::class)->processingQueueForTenant($tenant->id);
        Queue::assertPushed(ProcessTransactionJob::class, function (ProcessTransactionJob $job) use ($queue, $successfulTransaction) {
            return $job->queue === $queue && $this->jobTransactionId($job) === $successfulTransaction->id;
        });

        $intake->refresh();
        $this->assertSame(TransactionIntake::PROCESSING_STATUS_PROCESSED, $intake->processing_status);
        $this->assertSame('PARTIAL_BATCH_FAILURE', $intake->last_error_code);
        $this->assertStringContainsString($failedTransactionId, (string) $intake->last_error_message);
    }

    public function test_post_insert_receipt_race_falls_back_to_duplicate_receipt_conflict(): void
    {
        $tenant = Tenant::factory()->create();
        $terminal = PosTerminal::factory()->create(['tenant_id' => $tenant->id]);
        $existing = Transaction::factory()->create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => 'EXISTING-' . Str::upper(Str::random(8)),
            'hardware_id' => $terminal->serial_number,
            'receipt_no' => 'RACE-' . Str::upper(Str::random(8)),
            'transaction_timestamp' => now()->subMinute(),
            'payload_checksum' => str_repeat('c', 64),
        ]);

        $service = new class(app(DeadlockRetryService::class)) extends TransactionIngestService {
            private bool $firstReceiptCheck = true;

            protected function findReceiptConflict(array $parent): ?object
            {
                if ($this->firstReceiptCheck) {
                    $this->firstReceiptCheck = false;
                    return null;
                }

                return parent::findReceiptConflict($parent);
            }

            protected function insertTransactionParent(array $parent): int
            {
                return 0;
            }
        };

        $result = $service->ingest(array_merge(
            $this->transactionPayload('INCOMING-' . Str::upper(Str::random(8)), $terminal->serial_number),
            [
                'tenant_id' => $tenant->id,
                'terminal_id' => $terminal->id,
                'receipt_no' => $existing->receipt_no,
                'transaction_timestamp' => $existing->transaction_timestamp->copy()->addMinute()->format('Y-m-d H:i:s'),
                'payload_checksum' => str_repeat('d', 64),
            ]
        ));

        $this->assertSame('duplicate', $result['status']);
        $this->assertSame('duplicate_receipt_conflict', $result['message']);
        $this->assertSame($existing->id, $result['id']);
    }

    private function transactionPayload(string $transactionId, string $hardwareId): array
    {
        return [
            'transaction_id' => $transactionId,
            'hardware_id' => $hardwareId,
            'receipt_no' => 'PB-' . Str::upper(Str::random(8)),
            'transaction_timestamp' => now()->subMinute()->format('Y-m-d\TH:i:s\Z'),
            'gross_sales' => 100.0,
            'net_sales' => 100.0,
            'promo_status' => 'NONE',
            'customer_code' => 'C-TEST',
            'payload_checksum' => str_repeat('b', 64),
            'adjustments' => [
                ['adjustment_type' => 'promo_discount', 'amount' => 0],
                ['adjustment_type' => 'senior_discount', 'amount' => 0],
                ['adjustment_type' => 'pwd_discount', 'amount' => 0],
                ['adjustment_type' => 'vip_card_discount', 'amount' => 0],
                ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0],
                ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0],
                ['adjustment_type' => 'employee_discount', 'amount' => 0],
            ],
            'taxes' => [
                ['tax_type' => 'VAT', 'amount' => 0],
                ['tax_type' => 'VATABLE_SALES', 'amount' => 100],
                ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 0],
                ['tax_type' => 'OTHER_TAX', 'amount' => 0],
            ],
        ];
    }

    private function failingTransactionPayload(string $transactionId, string $hardwareId): array
    {
        $payload = $this->transactionPayload($transactionId, $hardwareId);
        unset($payload['gross_sales']);

        return $payload;
    }

    private function jobTransactionId(ProcessTransactionJob $job): int
    {
        $property = new \ReflectionProperty($job, 'transactionId');
        $property->setAccessible(true);

        return (int) $property->getValue($job);
    }
}
