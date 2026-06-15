<?php

namespace App\Exports;

use App\Models\Transaction;
use Carbon\Carbon;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionLogsExport
{
    protected $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        $basis = in_array($this->filters['date_basis'] ?? null, ['created', 'completed', 'transaction'], true)
            ? $this->filters['date_basis']
            : 'transaction';

        $dateColumn = match ($basis) {
            'created' => 'created_at',
            'completed' => 'completed_at',
            default => 'transaction_timestamp',
        };

        return Transaction::query()
            ->with([
                'terminal:id,serial_number,tenant_id,machine_number',
                'terminal.tenant:id,trade_name',
                'tenant:id,trade_name',
                'adjustments',
                'taxes',
            ])
            ->when($this->filters['status'] ?? null, function($query, $status) {
                $query->where('validation_status', $status);
            })
            ->when($this->filters['transaction_id'] ?? null, function ($query, $transactionId) {
                $search = str_replace('TX-', '', trim($transactionId));
                $query->where('transaction_id', 'like', "%{$search}%");
            })
            ->when($this->filters['date'] ?? null, function ($query, $date) {
                $query->whereDate('created_at', $date);
            })
            ->when($this->filters['date_from'] ?? null, function ($query, $dateFrom) use ($dateColumn) {
                if ($dateColumn === 'transaction_timestamp') {
                    $query->where(function ($q) use ($dateFrom) {
                        $q->where(function ($subQ) use ($dateFrom) {
                            $subQ->whereNotNull('transaction_timestamp')
                                ->where('transaction_timestamp', '>=', $dateFrom . ' 00:00:00');
                        })->orWhere(function ($subQ) use ($dateFrom) {
                            $subQ->whereNull('transaction_timestamp')
                                ->where('created_at', '>=', $dateFrom . ' 00:00:00');
                        });
                    });

                    return;
                }

                $query->where($dateColumn, '>=', $dateFrom . ' 00:00:00');
            })
            ->when($this->filters['date_to'] ?? null, function ($query, $dateTo) use ($dateColumn) {
                if ($dateColumn === 'transaction_timestamp') {
                    $query->where(function ($q) use ($dateTo) {
                        $q->where(function ($subQ) use ($dateTo) {
                            $subQ->whereNotNull('transaction_timestamp')
                                ->where('transaction_timestamp', '<=', $dateTo . ' 23:59:59');
                        })->orWhere(function ($subQ) use ($dateTo) {
                            $subQ->whereNull('transaction_timestamp')
                                ->where('created_at', '<=', $dateTo . ' 23:59:59');
                        });
                    });

                    return;
                }

                $query->where($dateColumn, '<=', $dateTo . ' 23:59:59');
            })
            ->when($this->filters['tenant_id'] ?? null, function ($query, $tenantId) {
                $query->where('tenant_id', $tenantId);
            })
            ->when($this->filters['terminal_id'] ?? null, function ($query, $terminalId) {
                $query->where('terminal_id', $terminalId);
            })
            ->when($this->filters['amount_min'] ?? null, function ($query, $amount) {
                $query->where('gross_sales', '>=', $amount);
            })
            ->when($this->filters['amount_max'] ?? null, function ($query, $amount) {
                $query->where('gross_sales', '<=', $amount);
            })
            ->when($dateColumn === 'transaction_timestamp', function ($query) {
                $query->orderByRaw('COALESCE(transaction_timestamp, created_at) desc');
            }, function ($query) use ($dateColumn) {
                $query->orderBy($dateColumn, 'desc');
            });
    }

    public function headings(): array
    {
        return [
            'Transaction ID',
            'Tenant',
            'Terminal',
            'Receipt No',
            'Gross Sales',
            'Net Sales',
            'VAT',
            'Vatable Sales',
            'SC VAT Exempt Sales',
            'Tax Exempt',
            'Promo Discount',
            'Senior Discount',
            'PWD Discount',
            'VIP Card Discount',
            'Employee Discount',
            'Regular Discount',
            'Service Charge',
            'Management Service Charge',
            'Other Tax',
            'Validation Status',
            'Job Status',
            'Attempts',
            'Transaction Date',
            'Completed At',
            'Created At'
        ];
    }

    public function map($transaction): array
    {
        return [
            $transaction->transaction_id,
            $transaction->tenant->trade_name ?? $transaction->terminal?->tenant?->trade_name ?? 'N/A',
            $transaction->terminal?->machine_number ?? $transaction->terminal?->serial_number ?? 'N/A',
            $transaction->receipt_no ?? 'N/A',
            number_format((float) ($transaction->gross_sales ?? 0), 2),
            number_format((float) ($transaction->net_sales ?? 0), 2),
            number_format((float) ($transaction->vat_amount ?? 0), 2),
            number_format((float) ($transaction->vatable_sales ?? 0), 2),
            number_format($this->scVatExemptSales($transaction), 2),
            $this->taxExemptLabel($transaction),
            number_format($this->promoDiscount($transaction), 2),
            number_format($this->discountAmount($transaction, 'senior_discount', ['senior_discount', 'senior_citizen_discount', 'SENIOR']), 2),
            number_format($this->discountAmount($transaction, 'pwd_discount', ['pwd_discount', 'PWD']), 2),
            number_format($this->discountAmount($transaction, 'vip_card_discount', ['vip_card_discount', 'vip_discount', 'VIP']), 2),
            number_format($this->discountAmount($transaction, 'employee_discount', ['employee_discount', 'EMPLOYEE']), 2),
            number_format((float) ($transaction->discount_total ?? 0), 2),
            number_format((float) ($transaction->service_charge ?? 0), 2),
            number_format((float) ($transaction->management_service_charge ?? 0), 2),
            number_format($this->otherTax($transaction), 2),
            $transaction->validation_status,
            $transaction->job_status ?? 'N/A',
            $transaction->job_attempts ?? 0,
            $this->formatDate($transaction->transaction_timestamp),
            $this->formatDate($transaction->completed_at),
            $this->formatDate($transaction->created_at)
        ];
    }

    private function promoDiscount(Transaction $transaction): float
    {
        return $this->discountAmount($transaction, 'promo_discount', [
            'promo_discount',
            'promo_discount_amount',
            'PROMO',
        ]);
    }

    private function discountAmount(Transaction $transaction, string $attribute, array $adjustmentTypes): float
    {
        $directAmount = (float) ($transaction->{$attribute} ?? 0);

        if ($directAmount !== 0.0) {
            return $directAmount;
        }

        return $this->sumRelatedAmounts($transaction, 'adjustments', 'adjustment_type', $adjustmentTypes);
    }

    private function scVatExemptSales(Transaction $transaction): float
    {
        $directAmount = (float) ($transaction->sc_vat_exempt_sales ?? 0);

        if ($directAmount !== 0.0) {
            return $directAmount;
        }

        return $this->sumRelatedAmounts($transaction, 'taxes', 'tax_type', [
            'SC_VAT_EXEMPT_SALES',
            'VAT_EXEMPT_SALES',
            'VATEXEMPT_SALES',
            'VAT-EXEMPT',
            'EXEMPT',
            'VATEXEMPT',
        ]);
    }

    private function otherTax(Transaction $transaction): float
    {
        return $this->sumRelatedAmounts($transaction, 'taxes', 'tax_type', [
            'VAT',
            'VAT_AMOUNT',
            'VATABLE_SALES',
            'SC_VAT_EXEMPT_SALES',
            'VAT_EXEMPT_SALES',
            'VATEXEMPT_SALES',
            'VAT-EXEMPT',
            'EXEMPT',
            'VATEXEMPT',
            'ZERO_RATED',
            'NON-VAT',
            'NON_VAT',
            'ZERO-RATED',
        ], true);
    }

    private function sumRelatedAmounts(Transaction $transaction, string $relation, string $typeColumn, array $types, bool $exclude = false): float
    {
        $rows = $transaction->relationLoaded($relation)
            ? $transaction->getRelation($relation)
            : $transaction->{$relation};

        if (!$rows) {
            return 0.0;
        }

        $normalizedTypes = array_map(fn ($type) => strtoupper((string) $type), $types);

        return (float) $rows
            ->filter(function ($row) use ($typeColumn, $normalizedTypes, $exclude) {
                $rowType = strtoupper((string) ($row->{$typeColumn} ?? ''));
                $matches = in_array($rowType, $normalizedTypes, true);

                return $exclude ? !$matches : $matches;
            })
            ->sum('amount');
    }

    private function taxExemptLabel(Transaction $transaction): string
    {
        if ($transaction->tax_exempt === null) {
            return 'N/A';
        }

        return filter_var($transaction->tax_exempt, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
    }

    private function formatDate($value): string
    {
        if (empty($value)) {
            return 'N/A';
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value)) {
            try {
                return Carbon::parse($value)->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return 'N/A';
            }
        }

        return 'N/A';
    }

    public function download(string $filename): StreamedResponse
    {
        return response()->streamDownload(function () {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Transaction Logs');
            $sheet->fromArray($this->headings(), null, 'A1');

            $row = 2;
            foreach ($this->query()->lazy(1000) as $transaction) {
                $sheet->fromArray($this->map($transaction), null, 'A' . $row);
                $row++;
            }

            foreach (range('A', 'X') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
