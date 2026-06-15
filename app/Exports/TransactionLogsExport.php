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
            ->with(['terminal:id,serial_number,tenant_id,machine_number', 'terminal.tenant:id,trade_name', 'tenant:id,trade_name'])
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
            'Amount',
            'Net Sales',
            'VAT',
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
            $transaction->validation_status,
            $transaction->job_status ?? 'N/A',
            $transaction->job_attempts ?? 0,
            $this->formatDate($transaction->transaction_timestamp),
            $this->formatDate($transaction->completed_at),
            $this->formatDate($transaction->created_at)
        ];
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

            foreach (range('A', 'M') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
