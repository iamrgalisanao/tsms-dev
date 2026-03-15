<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Services\DeadlockRetryService;
use Illuminate\Support\Arr;

final class TransactionIngestService
{
    protected DeadlockRetryService $retryService;

    public function __construct(DeadlockRetryService $retryService)
    {
        $this->retryService = $retryService;
    }

    /**
     * Ingest a transaction payload atomically with upsert and deadlock retry.
     *
     * @param array $payload
     * @return array
     */
    public function ingest(array $payload): array
    {
        return $this->retryService->withDeadlockRetry(function () use ($payload) {
            DB::table('transactions')->upsert(
                [$payload],
                ['terminal_id', 'transaction_id'],
                [
                    'updated_at',
                    'submission_uuid',
                    'submission_timestamp',
                    'payload_checksum',
                    'original_payload',
                    'validation_status',
                ]
            );

            $transaction = DB::table('transactions')
                ->where('terminal_id', $payload['terminal_id'])
                ->where('transaction_id', $payload['transaction_id'])
                ->first();

            return [
                'id' => $transaction->id,
                'transaction_id' => $transaction->transaction_id,
                'status' => 'accepted',
            ];
        });
    }
}
