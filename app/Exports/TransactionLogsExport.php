<?php

namespace App\Exports;

use App\Models\Transaction;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class TransactionLogsExport implements FromQuery, WithMapping, WithHeadings, ShouldAutoSize, WithChunkReading
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
        
        // Select transactions.* and request distinct rows to avoid possible duplicates
        // introduced by joined/filtering logic elsewhere.
        return Transaction::query()
            ->select('transactions.*')
            ->distinct()
            ->with(['terminal.tenant', 'tenant', 'adjustments'])
            // If a status filter is provided, apply it. Otherwise default to
            // exporting only VALID transactions when the schema supports
            // receipt_no (so exports align with POS-style counts). If the
            // receipt_no column is absent, preserve legacy behavior.
            ->when(isset($this->filters['status']), function($query) {
                if ($this->filters['status'] === 'VOIDED') {
                    $query->whereNotNull('voided_at');
                    return;
                }

                if ($this->filters['status'] === 'REFUNDED') {
                    $query->where('is_refunded', true);
                    return;
                }

                $query->where('validation_status', $this->filters['status']);
            }, function($query) {
                if (Schema::hasColumn('transactions', 'receipt_no')) {
                    // Default exporter excludes DUPLICATE rows by default so
                    // exported counts better match POS-style unique receipt
                    // counts while still including PENDING/ERROR rows.
                    $query->where('validation_status', '!=', 'DUPLICATE');
                }
            })
            ->when($this->filters['date_from'] ?? null, function($query) use ($dateColumn) {
                $this->applyDateFromFilter($query, $dateColumn);
            })
            ->when($this->filters['date_to'] ?? null, function($query) use ($dateColumn) {
                $this->applyDateToFilter($query, $dateColumn);
            })
            ->when($this->filters['tenant_id'] ?? null, function($query, $tenantId) {
                $query->where(function ($q) use ($tenantId) {
                    $q->where('tenant_id', $tenantId)
                        ->orWhereHas('terminal', function ($terminalQuery) use ($tenantId) {
                            $terminalQuery->where('tenant_id', $tenantId);
                        });
                });
            })
            ->when($this->filters['terminal_id'] ?? null, function($query, $terminalId) {
                $query->where('terminal_id', $terminalId);
            })
            ->when($this->filters['transaction_id'] ?? null, function($query, $transactionId) {
                $search = str_replace('TX-', '', trim($transactionId));

                $query->where(function ($q) use ($search) {
                    $q->where('transaction_id', 'like', "%{$search}%");

                    if (Schema::hasColumn('transactions', 'receipt_no')) {
                        $q->orWhere('receipt_no', 'like', "%{$search}%");
                    }

                    $q->orWhereHas('terminal', function ($terminalQuery) use ($search) {
                        $terminalQuery
                            ->where('serial_number', 'like', "%{$search}%")
                            ->orWhere('machine_number', 'like', "%{$search}%");
                    });

                    $q->orWhereHas('tenant', function ($tenantQuery) use ($search) {
                        $tenantQuery->where('trade_name', 'like', "%{$search}%");
                    });
                });
            })
            ->when($this->filters['amount_min'] ?? null, function($query, $amountMin) {
                $query->where('gross_sales', '>=', $amountMin);
            })
            ->when($this->filters['amount_max'] ?? null, function($query, $amountMax) {
                $query->where('gross_sales', '<=', $amountMax);
            });
    }

    public function headings(): array
    {
        return [
            'Transaction ID',
            'Receipt No',
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
        
        // Transaction timestamp with fallback like display logic. Some deployed
        // schemas/casts return transaction_timestamp as a raw string, so avoid
        // assuming every date-like value is already a Carbon instance.
        $txTime = $transaction->transaction_timestamp ?? $transaction->created_at;
        
        return [
            $transaction->transaction_id,
            $transaction->receipt_no ?? '-',
            $tenantTerminal,
            number_format($transaction->amount ?? $transaction->gross_sales ?? 0, 2),
            number_format($transaction->net_sales ?? 0, 2),
            // Adjustment Columns (match display table structure)
            number_format($transaction->promo_discount ?? 0, 2),
            number_format($transaction->senior_discount ?? 0, 2),
            number_format($transaction->pwd_discount ?? 0, 2),
            number_format($transaction->adjustments->where('adjustment_type', 'VIP')->sum('amount'), 2),
            number_format($transaction->adjustments->where('adjustment_type', 'EMPLOYEE')->sum('amount'), 2),
            number_format($transaction->service_charge ?? 0, 2),
            number_format($transaction->management_service_charge ?? 0, 2),
            // Tax Columns
            number_format($transaction->vat_amount ?? 0, 2),
            number_format($transaction->vatable_sales ?? 0, 2),
            number_format($transaction->sc_vat_exempt_sales ?? 0, 2),
            number_format($transaction->tax_exempt ?? 0, 2),
            '-', // Other Tax - not available in database
            // Status & Timestamps
            $transaction->validation_status,
            $transaction->job_status ?? $transaction->latest_job_status ?? 'N/A',
            $transaction->job_attempts ?? 0,
            $this->formatDateTime($txTime),
            $this->formatDateTime($transaction->created_at)
        ];
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
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

    /**
     * Configure the chunk size for chunk reading.
     *
     * Laravel Excel requires this when implementing WithChunkReading.
     */
    public function chunkSize(): int
    {
        return 1000;
    }
}
