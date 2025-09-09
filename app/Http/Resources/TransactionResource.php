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
            'terminal_id' => $this->terminal_id,
            'tenant_id' => $this->tenant_id,
            'customer_code' => $this->customer_code,
            'gross_sales' => (float) $this->gross_sales,
            'net_sales' => (float) $this->net_sales,
            'transaction_timestamp' => $this->transaction_timestamp,
            'validation_status' => $this->validation_status,
            'job_status' => $this->job_status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'terminal' => $this->whenLoaded('terminal', function () {
                return [
                    'id' => $this->terminal->id,
                    'serial_number' => $this->terminal->serial_number,
                    'terminal_uid' => $this->terminal->terminal_uid,
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
        ];
    }
}