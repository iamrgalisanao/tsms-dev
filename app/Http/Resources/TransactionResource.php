<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'original_transaction_id' => $this->original_transaction_id,
            'hardware_id' => $this->hardware_id,
            'terminal_id' => $this->terminal_id,
            'customer_code' => $this->customer_code,
            'promo_status' => $this->promo_status,

            // Financial data
            'gross_sales' => (float) $this->gross_sales,
            'vatable_sales' => (float) $this->vatable_sales,
            'vat_amount' => (float) $this->vat_amount,
            'net_sales' => (float) $this->net_sales,
            'tax_exempt' => (bool) $this->tax_exempt,
            'service_charge' => (float) $this->service_charge,
            'management_service_charge' => (float) $this->management_service_charge,

            // Refund data
            'refund_status' => $this->refund_status,
            'refund_amount' => (float) $this->refund_amount,
            'refund_reason' => $this->refund_reason,
            'refund_reference_id' => $this->refund_reference_id,
            'refund_processed_at' => $this->refund_processed_at,

            // Void data
            'voided_at' => $this->voided_at,
            'void_reason' => $this->void_reason,

            // Status and processing
            'validation_status' => $this->validation_status,
            'job_status' => $this->job_status,
            'latest_job_status' => $this->latest_job_status,
            'last_error' => $this->last_error,
            'job_attempts' => (int) $this->job_attempts,
            'completed_at' => $this->completed_at,

            // Timestamps
            'transaction_timestamp' => $this->transaction_timestamp,
            'submission_timestamp' => $this->submission_timestamp,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Metadata
            'submission_uuid' => $this->submission_uuid,
            'payload_checksum' => $this->payload_checksum,

            // Relationships
            'terminal' => $this->whenLoaded('terminal', function () {
                return [
                    'id' => $this->terminal->id,
                    'terminal_uid' => $this->terminal->terminal_uid,
                    'serial_number' => $this->terminal->serial_number,
                    'status' => $this->terminal->status,
                    'is_active' => (bool) $this->terminal->is_active,
                ];
            }),

            'tenant' => $this->whenLoaded('tenant', function () {
                return [
                    'id' => $this->tenant->id,
                    'name' => $this->tenant->name,
                    'trade_name' => $this->tenant->trade_name,
                    'customer_code' => $this->tenant->customer_code,
                ];
            }),

            'jobs' => $this->whenLoaded('jobs', function () {
                return $this->jobs->map(function ($job) {
                    return [
                        'id' => $job->id,
                        'job_status' => $job->job_status,
                        'created_at' => $job->created_at,
                        'updated_at' => $job->updated_at,
                    ];
                });
            }),

            // Computed properties
            'is_voided' => $this->isVoided(),
            'is_refunded' => $this->isRefunded(),
            'can_refund' => $this->canRefund(),
            'processing_time_seconds' => $this->completed_at
                ? $this->created_at->diffInSeconds($this->completed_at)
                : null,
        ];
    }
}