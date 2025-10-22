<?php

namespace App\Exports;

use App\Models\Transaction;

class TransactionLogsExport
{
    protected $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        // Mirror the controller's date basis logic exactly
        $dateBasis = $this->getDateBasis();
        $dateColumn = $this->getDateColumn($dateBasis);
        
        return Transaction::query()
            ->with(['terminal', 'tenant'])
            ->when($this->filters['status'] ?? null, function($query, $status) {
                $query->where('validation_status', $status);
            })
            ->when($this->filters['date_from'] ?? null, function($query) use ($dateColumn) {
                $this->applyDateFromFilter($query, $dateColumn);
            })
            ->when($this->filters['date_to'] ?? null, function($query) use ($dateColumn) {
                $this->applyDateToFilter($query, $dateColumn);
            })
            ->when($this->filters['tenant_id'] ?? null, function($query, $tenantId) {
                $query->where('tenant_id', $tenantId);
            })
            ->when($this->filters['terminal_id'] ?? null, function($query, $terminalId) {
                $query->where('terminal_id', $terminalId);
            })
            ->when($this->filters['transaction_id'] ?? null, function($query, $transactionId) {
                $search = str_replace('TX-', '', $transactionId);
                $query->where('transaction_id', 'like', "%{$search}%");
            });
    }

    public function headings(): array
    {
        return [
            'Transaction ID',
            'Tenant / Terminal',
            'Gross Sales',
            'Net Sales',
            // Adjustment Columns
            'Promo Discount',
            'Senior Discount', 
            'PWD Discount',
            'VIP Card Discount',
            'Employee Discount',
            'Service Charge (Employees)',
            'Service Charge (Management)',
            // Tax Columns
            'VAT',
            'Vatable Sales',
            'SC VAT Exempt Sales',
            'Tax Exempt',
            'Other Tax',
            // Status & Timestamps
            'Validation Status',
            'Job Status',
            'Attempts',
            'Transaction Time',
            'Created At'
        ];
    }

    public function map($transaction): array
    {
        // Format tenant/terminal info like the display table
        $tenantName = optional(optional($transaction->terminal)->tenant)->trade_name ?? 'Unknown Tenant';
        $serial = optional($transaction->terminal)->serial_number ?? 'N/A';
        $machine = optional($transaction->terminal)->machine_number ?? 'N/A';
        $tenantTerminal = "{$tenantName} • SN: {$serial} • Machine: {$machine}";
        
        // Transaction timestamp with fallback like display logic
        $txTime = $transaction->transaction_timestamp ?? $transaction->created_at;
        
        return [
            $transaction->transaction_id,
            $tenantTerminal,
            number_format($transaction->amount ?? $transaction->gross_sales ?? 0, 2),
            number_format($transaction->net_sales ?? 0, 2),
            // Adjustment Columns (match display table structure)
            number_format($transaction->promo_discount ?? 0, 2),
            number_format($transaction->senior_discount ?? 0, 2),
            number_format($transaction->pwd_discount ?? 0, 2),
            '-', // VIP Card Discount - not available in database
            '-', // Employee Discount - not available in database  
            number_format($transaction->service_charge ?? 0, 2),
            number_format($transaction->management_service_charge ?? 0, 2),
            // Tax Columns
            number_format($transaction->vat ?? 0, 2),
            number_format($transaction->vatable_sales ?? 0, 2),
            number_format($transaction->sc_vat_exempt_sales ?? 0, 2),
            number_format($transaction->tax_exempt ?? 0, 2),
            '-', // Other Tax - not available in database
            // Status & Timestamps
            $transaction->validation_status,
            $transaction->job_status ?? $transaction->latest_job_status ?? 'N/A',
            $transaction->job_attempts ?? 0,
            $txTime ? $txTime->format('Y-m-d H:i:s') : null,
            $transaction->created_at->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Get the date basis from filters, defaulting to 'completed'
     */
    public function getDateBasis(): string
    {
        return in_array($this->filters['date_basis'] ?? null, ['created', 'completed', 'transaction']) 
            ? $this->filters['date_basis'] 
            : 'completed';
    }

    /**
     * Get the appropriate date column based on date basis
     */
    public function getDateColumn(string $dateBasis): string
    {
        return match($dateBasis) {
            'created' => 'created_at',
            'transaction' => 'transaction_timestamp', 
            default => 'completed_at'
        };
    }

    /**
     * Apply date_from filter using the same logic as TransactionLogController
     */
    protected function applyDateFromFilter($query, string $dateColumn): void
    {
        if ($dateColumn === 'transaction_timestamp') {
            // Use transaction_timestamp as primary with created_at as fallback only for NULL values
            $query->where(function ($q) {
                $q->where(function ($subQ) {
                    // Primary: transaction_timestamp is not null and within range
                    $subQ->whereNotNull('transaction_timestamp')
                         ->where('transaction_timestamp', '>=', $this->filters['date_from'] . ' 00:00:00');
                })->orWhere(function ($subQ) {
                    // Fallback: transaction_timestamp is null, use created_at
                    $subQ->whereNull('transaction_timestamp')
                         ->where('created_at', '>=', $this->filters['date_from'] . ' 00:00:00');
                });
            });
        } else {
            $query->where($dateColumn, '>=', $this->filters['date_from'] . ' 00:00:00');
        }
    }

    /**
     * Apply date_to filter using the same logic as TransactionLogController
     */
    protected function applyDateToFilter($query, string $dateColumn): void
    {
        if ($dateColumn === 'transaction_timestamp') {
            // Use transaction_timestamp as primary with created_at as fallback only for NULL values
            $query->where(function ($q) {
                $q->where(function ($subQ) {
                    // Primary: transaction_timestamp is not null and within range
                    $subQ->whereNotNull('transaction_timestamp')
                         ->where('transaction_timestamp', '<=', $this->filters['date_to'] . ' 23:59:59');
                })->orWhere(function ($subQ) {
                    // Fallback: transaction_timestamp is null, use created_at
                    $subQ->whereNull('transaction_timestamp')
                         ->where('created_at', '<=', $this->filters['date_to'] . ' 23:59:59');
                });
            });
        } else {
            $query->where($dateColumn, '<=', $this->filters['date_to'] . ' 23:59:59');
        }
    }
}