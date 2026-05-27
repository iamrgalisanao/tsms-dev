<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\PosTerminal;
use App\Models\Transaction;
use App\Models\TransactionIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubmissionStatusController extends Controller
{
    public function show(Request $request, string $submissionUuid): JsonResponse
    {
        if (!Str::isUuid($submissionUuid)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid submission UUID.',
                'error_code' => 'INVALID_SUBMISSION_UUID',
            ], 422);
        }

        $query = TransactionIntake::query()
            ->where('submission_uuid', $submissionUuid);

        $this->applyActorScope($query, $request);

        $intake = $query->first();

        if (!$intake) {
            return response()->json([
                'success' => false,
                'message' => 'Submission not found.',
                'error_code' => 'SUBMISSION_NOT_FOUND',
            ], 404);
        }

        $transaction = $this->findTransaction($intake, $request);
        $payloadTransaction = is_array($intake->payload)
            ? ($intake->payload['transaction'] ?? [])
            : [];

        return response()->json([
            'success' => true,
            'data' => [
                'submission_uuid' => $intake->submission_uuid,
                'intake_id' => $intake->id,
                'provider_status' => $this->providerStatus($intake),
                'intake_status' => $intake->intake_status,
                'processing_status' => $intake->processing_status,
                'last_error_code' => $intake->last_error_code,
                'last_error_message' => $this->decodeStoredMessage($intake->last_error_message),
                'received_at' => optional($intake->received_at)->toISOString(),
                'queued_at' => optional($intake->queued_at)->toISOString(),
                'processed_at' => optional($intake->processed_at)->toISOString(),
                'tenant_id' => $intake->tenant_id,
                'terminal_id' => $intake->terminal_id,
                'transaction' => [
                    'transaction_id' => $transaction?->transaction_id ?? ($payloadTransaction['transaction_id'] ?? null),
                    'receipt_no' => $transaction?->receipt_no ?? ($payloadTransaction['receipt_no'] ?? null),
                    'transaction_timestamp' => optional($transaction?->transaction_timestamp)->toISOString()
                        ?? ($payloadTransaction['transaction_timestamp'] ?? null),
                ],
            ],
        ]);
    }

    private function applyActorScope($query, Request $request): void
    {
        $actor = $request->user();

        if ($actor instanceof PosTerminal) {
            $query->where('tenant_id', $actor->tenant_id)
                ->where('terminal_id', $actor->id);

            return;
        }

        if (isset($actor->tenant_id)) {
            $query->where('tenant_id', $actor->tenant_id);
        }
    }

    private function findTransaction(TransactionIntake $intake, Request $request): ?Transaction
    {
        $query = Transaction::query()
            ->where('submission_uuid', $intake->submission_uuid)
            ->where('tenant_id', $intake->tenant_id);

        $actor = $request->user();
        if ($actor instanceof PosTerminal) {
            $query->where('terminal_id', $actor->id);
        } elseif ($intake->terminal_id) {
            $query->where('terminal_id', $intake->terminal_id);
        }

        return $query->latest('id')->first();
    }

    private function providerStatus(TransactionIntake $intake): string
    {
        return match ($intake->processing_status) {
            TransactionIntake::PROCESSING_STATUS_PROCESSED => 'processed',
            TransactionIntake::PROCESSING_STATUS_PROCESSING => 'processing',
            TransactionIntake::PROCESSING_STATUS_DUPLICATE => 'duplicate',
            TransactionIntake::PROCESSING_STATUS_FAILED_RETRYABLE => 'failed_retryable',
            TransactionIntake::PROCESSING_STATUS_FAILED_PERMANENT,
            TransactionIntake::PROCESSING_STATUS_DEAD_LETTERED => 'failed_permanent',
            default => match ($intake->intake_status) {
                TransactionIntake::INTAKE_STATUS_REJECTED => 'rejected',
                TransactionIntake::INTAKE_STATUS_QUEUED => 'queued',
                TransactionIntake::INTAKE_STATUS_ACCEPTED,
                TransactionIntake::INTAKE_STATUS_RECEIVED => 'accepted',
                default => 'unknown',
            },
        };
    }

    private function decodeStoredMessage(?string $message): mixed
    {
        if ($message === null || $message === '') {
            return null;
        }

        $decoded = json_decode($message, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $message;
    }
}
