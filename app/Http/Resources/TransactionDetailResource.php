<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Basic transaction info
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'original_transaction_id' => $this->original_transaction_id,
            'hardware_id' => $this->hardware_id,
            'customer_code' => $this->customer_code,
            'promo_status' => $this->promo_status,

            // Financial breakdown
            'financial_data' => [
                'gross_sales' => (float) $this->gross_sales,
                'vatable_sales' => (float) $this->vatable_sales,
                'vat_amount' => (float) $this->vat_amount,
                'net_sales' => (float) $this->net_sales,
                'tax_exempt' => (bool) $this->tax_exempt,
                'service_charge' => (float) $this->service_charge,
                'management_service_charge' => (float) $this->management_service_charge,
                'total_charges' => (float) $this->service_charge + (float) $this->management_service_charge,
            ],

            // Refund information
            'refund_info' => [
                'status' => $this->refund_status,
                'amount' => (float) $this->refund_amount,
                'reason' => $this->refund_reason,
                'reference_id' => $this->refund_reference_id,
                'processed_at' => $this->refund_processed_at,
                'is_refunded' => $this->isRefunded(),
                'can_refund' => $this->canRefund(),
            ],

            // Void information
            'void_info' => [
                'is_voided' => $this->isVoided(),
                'voided_at' => $this->voided_at,
                'void_reason' => $this->void_reason,
            ],

            // Processing status
            'processing_status' => [
                'validation_status' => $this->validation_status,
                'job_status' => $this->job_status,
                'latest_job_status' => $this->latest_job_status,
                'last_error' => $this->last_error,
                'job_attempts' => (int) $this->job_attempts,
                'completed_at' => $this->completed_at,
                'processing_time_seconds' => $this->completed_at
                    ? $this->created_at->diffInSeconds($this->completed_at)
                    : null,
            ],

            // Timestamps
            'timestamps' => [
                'transaction_timestamp' => $this->transaction_timestamp,
                'submission_timestamp' => $this->submission_timestamp,
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ],

            // Metadata
            'metadata' => [
                'submission_uuid' => $this->submission_uuid,
                'payload_checksum' => $this->payload_checksum,
            ],

            // Relationships
            'terminal' => $this->whenLoaded('terminal', function () {
                return [
                    'id' => $this->terminal->id,
                    'terminal_uid' => $this->terminal->terminal_uid,
                    'serial_number' => $this->terminal->serial_number,
                    'status' => $this->terminal->status,
                    'is_active' => (bool) $this->terminal->is_active,
                    'enrolled_at' => $this->terminal->enrolled_at,
                    'last_seen' => $this->terminal->last_seen,
                ];
            }),

            'tenant' => $this->whenLoaded('tenant', function () {
                return [
                    'id' => $this->tenant->id,
                    'name' => $this->tenant->trade_name,
                    'trade_name' => $this->tenant->trade_name,
                    'customer_code' => $this->tenant->customer_code,
                    'address' => $this->tenant->address,
                    'contact_info' => $this->tenant->contact_info,
                ];
            }),

            'adjustments' => $this->whenLoaded('adjustments', function () {
                return $this->adjustments->map(function ($adjustment) {
                    return [
                        'id' => $adjustment->id,
                        'type' => $adjustment->type,
                        'amount' => (float) $adjustment->amount,
                        'reason' => $adjustment->reason,
                        'created_at' => $adjustment->created_at,
                    ];
                });
            }),

            'taxes' => $this->whenLoaded('taxes', function () {
                return $this->taxes->map(function ($tax) {
                    return [
                        'id' => $tax->id,
                        'type' => $tax->type,
                        'rate' => (float) $tax->rate,
                        'amount' => (float) $tax->amount,
                        'description' => $tax->description,
                    ];
                });
            }),

            'jobs' => $this->whenLoaded('jobs', function () {
                return $this->jobs->map(function ($job) {
                    return [
                        'id' => $job->id,
                        'job_status' => $job->job_status,
                        'job_type' => $job->job_type,
                        'error_message' => $job->error_message,
                        'attempts' => $job->attempts,
                        'created_at' => $job->created_at,
                        'updated_at' => $job->updated_at,
                        'completed_at' => $job->completed_at,
                    ];
                });
            }),

            'validations' => $this->whenLoaded('validations', function () {
                return $this->validations->map(function ($validation) {
                    return [
                        'id' => $validation->id,
                        'rule_name' => $validation->rule_name,
                        'status' => $validation->status,
                        'error_message' => $validation->error_message,
                        'validated_at' => $validation->validated_at,
                        'created_at' => $validation->created_at,
                    ];
                });
            }),

            // Computed fields
            'computed' => [
                'is_stale' => $this->isPendingStale(30), // 30 minutes threshold
                'has_errors' => !empty($this->last_error),
                'needs_attention' => $this->validation_status === 'INVALID' || $this->job_status === 'FAILED',
            ],
        ];
    }
}
