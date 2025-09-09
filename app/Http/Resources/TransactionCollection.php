<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TransactionCollection extends ResourceCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = TransactionResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total' => $this->resource->total(),
                'per_page' => $this->resource->perPage(),
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
            ],
            'links' => [
                'first' => $this->resource->url(1),
                'last' => $this->resource->url($this->resource->lastPage()),
                'prev' => $this->resource->previousPageUrl(),
                'next' => $this->resource->nextPageUrl(),
            ],
            'summary' => $this->getSummaryData(),
        ];
    }

    /**
     * Get summary data for the collection
     *
     * @return array
     */
    private function getSummaryData(): array
    {
        $transactions = $this->collection;

        return [
            'total_gross_sales' => $transactions->sum('gross_sales'),
            'total_net_sales' => $transactions->sum('net_sales'),
            'total_vat_amount' => $transactions->sum('vat_amount'),
            'total_transactions' => $transactions->count(),
            'valid_transactions' => $transactions->where('validation_status', 'VALID')->count(),
            'failed_transactions' => $transactions->where('validation_status', 'INVALID')->count(),
            'pending_transactions' => $transactions->where('validation_status', 'PENDING')->count(),
            'voided_transactions' => $transactions->where('is_voided', true)->count(),
            'refunded_transactions' => $transactions->where('is_refunded', true)->count(),
        ];
    }
}
