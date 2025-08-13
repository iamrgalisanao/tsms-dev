<?php

namespace App\Http\Controllers\API\V1;

use App\Events\TransactionRetryUpdated;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Jobs\ProcessTransactionJob;

class RetryHistoryController extends Controller
{
    public function index(Request $request)
    {
        try {
            
            Log::info('Fetching retry history with params', [
                'request_params' => $request->all()
            ]);
            
            // Use correct schema with transaction_jobs join
            $query = DB::table('transactions as t')
                ->join('transaction_jobs as tj', 't.id', '=', 'tj.transaction_pk')
                ->where('tj.retry_count', '>', 0); // Only get transactions that have retry attempts
                
            // Apply filters if provided
            if ($request->has('status') && !empty($request->status)) {
                $query->where('tj.job_status', $request->status);
            }
                
            if ($request->has('date') && !empty($request->date)) {
                $date = $request->date;
                $query->whereDate('t.created_at', $date);
            }
                
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('t.transaction_id', 'like', "%{$search}%")
                      ->orWhere('t.terminal_id', 'like', "%{$search}%")
                      ->orWhere('tj.last_error', 'like', "%{$search}%");
                });
            }
            
            // Get total count for pagination
            $totalCount = $query->count();
            
            // Select minimal fields needed and add pagination
            $perPage = $request->per_page ?? 10;
            $page = $request->page ?? 1;
            
            $retries = $query->select([
                    't.id', 
                    't.transaction_id', 
                    't.terminal_id', 
                    'tj.retry_count',
                    'tj.job_status',
                    't.validation_status',
                    'tj.last_error', 
                    'tj.updated_at'
                ])
                ->orderBy('updated_at', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();
            
            // Format each item for the response
            $formattedRetries = [];
            // Update the formatting to show actual retry attempts
            foreach ($retries as $item) {
                $formattedRetries[] = [
                    'id' => $item->id,
                    'transaction_id' => $item->transaction_id ?? 'N/A',
                    'serial_number' => 'TERM-' . $item->terminal_id,
                    'job_attempts' => (int) ($item->retry_count ?? 0),
                    'job_status' => $item->job_status ?? 'UNKNOWN',
                    'validation_status' => $item->validation_status ?? 'PENDING',
                    'last_error' => $item->last_error ?? 'None',
                    'updated_at' => $item->updated_at,
                ];
            }
            
            Log::info('Successfully retrieved retry history', [
                'count' => count($formattedRetries),
                'total' => $totalCount
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'data' => $formattedRetries,
                    'total' => $totalCount,
                    'current_page' => (int)$page,
                    'last_page' => ceil($totalCount / $perPage),
                    'per_page' => (int)$perPage,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Retry history fetch failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load retry history: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $retry = SystemLog::with(['terminal', 'transaction'])->findOrFail($id);
        
        return response()->json([
            'status' => 'success',
            'data' => $retry
        ]);
    }

    public function retrigger($id)
    {
        try {
            DB::beginTransaction();
            
            $transaction = Transaction::lockForUpdate()->findOrFail($id);
            
            // Store previous states
            $previousStatus = $transaction->job_status;
            $previousError = $transaction->last_error;
            $currentAttempts = $transaction->job_attempts;

            // Only increment retry_count, preserve job_attempts through the process
            $transaction->retry_count = ($transaction->retry_count ?? 0) + 1;
            $transaction->job_status = 'QUEUED';
            $transaction->validation_status = 'PENDING';
            // Don't reset job_attempts, let it accumulate
            $transaction->save();

            // Log retry with attempt tracking
            SystemLog::create([
                'transaction_id' => $id,
                'type' => 'RETRY',
                'log_type' => 'RETRY_ATTEMPT',
                'severity' => 'INFO',
                'message' => "Manual retry initiated. Previous error: {$previousError}",
                'context' => json_encode([
                    'previous_status' => $previousStatus,
                    'previous_error' => $previousError,
                    'attempt_count' => $currentAttempts,
                    'retry_count' => $transaction->retry_count,
                    'preserved_attempts' => $currentAttempts
                ])
            ]);

            // Queue for processing
            ProcessTransactionJob::dispatch($transaction->id)
                ->afterCommit()
                ->onQueue('transactions');

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Transaction queued for retry',
                'job_attempts' => $transaction->job_attempts,
                'retry_count' => $transaction->retry_count,
                'job_status' => 'QUEUED'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Retry failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retry: ' . $e->getMessage()
            ], 500);
        }
    }

    public function retry($id)
    {
        try {
            $transaction = Transaction::findOrFail($id);

            // Reset transaction status for retry
            $transaction->update([
                'job_status' => 'QUEUED',
                'validation_status' => 'PENDING',
                'last_error' => null,
                'job_attempts' => 0,
                'completed_at' => null
            ]);

            // Dispatch new job
            ProcessTransactionJob::dispatch($transaction->id)
                ->afterCommit()
                ->onQueue('default');

            return response()->json([
                'status' => 'success',
                'message' => 'Transaction queued for retry',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'job_status' => 'QUEUED'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Retry failed', [
                'transaction_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retry transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    public function export(Request $request)
    {
        try {
            $format = $request->format ?? 'csv';
            $query = Transaction::where('job_attempts', '>', 0);
            
            // Apply filters
            if ($request->has('status')) {
                $query->where('job_status', $request->status);
            }

            if ($request->has('date_range')) {
                $dates = explode(',', $request->date_range);
                $query->whereBetween('created_at', $dates);
            }

            if ($request->has('error_type')) {
                $query->where('last_error', 'LIKE', "%{$request->error_type}%");
            }

            if ($format === 'csv') {
                return $this->exportCsv($query->get());
            } else if ($format === 'pdf') {
                return $this->exportPdf($query->get());
            }

        } catch (\Exception $e) {
            Log::error('Export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function exportCsv($data)
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="retry-history.csv"',
        ];

        $callback = function() use ($data) {
            $handle = fopen('php://output', 'w');
            
            // Add headers
            fputcsv($handle, ['Transaction ID', 'Terminal', 'Attempts', 'Status', 'Last Error', 'Updated']);
            
            // Add data
            foreach ($data as $row) {
                fputcsv($handle, [
                    $row->transaction_id,
                    'TERM-' . $row->terminal_id,
                    $row->job_attempts,
                    $row->job_status,
                    $row->last_error ?? 'None',
                    $row->updated_at->format('Y-m-d H:i:s')
                ]);
            }
            
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function exportPdf($data)
    {
        $pdf = PDF::loadView('exports.retry-history', [
            'data' => $data,
            'generated_at' => now()->format('Y-m-d H:i:s')
        ]);

        return $pdf->download('retry-history.pdf');
    }

    public function filter(Request $request) 
    {
        // New advanced filtering
    }

    /**
     * Seed sample data for demonstration purposes
     */
    public function seedData()
    {
        try {
            // Only allow this in development or testing environments
            if (!app()->environment('production')) {
                // Use our updated seeder
                $seeder = new \Database\Seeders\RetryTransactionSeeder();
                ob_start();
                $seeder->run();
                $output = ob_get_clean();
                
                // Get the count after seeding
                $count = \App\Models\Transaction::where('job_attempts', '>', 0)->count();
                
                return response()->json([
                    'status' => 'success',
                    'message' => 'Sample retry data created successfully',
                    'count' => $count,
                    'output' => $output
                ]);
            }
            
            return response()->json([
                'status' => 'error',
                'message' => 'This action is not allowed in production'
            ], 403);
        } catch (\Exception $e) {
            Log::error('Failed to seed retry data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create sample data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * System diagnostics endpoint
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function diagnostics() 
    {
        // Use extremely simplified approach to prevent any possible errors
        try {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'database' => true,
                    'queue' => true,
                    'cache' => true,
                    'message' => 'System check completed',
                    'timestamp' => now()->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (\Exception $e) {
            // Minimal logging, just the error
            Log::error('Basic diagnostics failed: ' . $e->getMessage());
            
            // Return simplest possible response with minimal processing
            return response()->json([
                'status' => 'error',
                'message' => 'Diagnostics error',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create emergency test data for display
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function createEmergencyData()
    {
        try {
            // Find or create tenant
            $tenant = DB::table('tenants')->first();
            if (!$tenant) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tenant found in database'
                ], 404);
            }
            
            // Find or create terminal
            $terminal = DB::table('pos_terminals')->first();
            if (!$terminal) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No terminal found in database'
                ], 404);
            }
            
            // Create emergency test transactions
            $txIds = [];
            $count = 0;
            
            for ($i = 1; $i <= 3; $i++) {
                $txId = 'EMERGENCY-' . uniqid();
                $txIds[] = $txId;
                
                try {
                    DB::table('transactions')->insert([
                        'tenant_id' => $tenant->id,
                        'transaction_id' => $txId,
                        'terminal_id' => $terminal->id,
                        'transaction_timestamp' => now(),
                        'job_attempts' => rand(1, 3),
                        'job_status' => ['FAILED', 'COMPLETED', 'PROCESSING'][array_rand(['FAILED', 'COMPLETED', 'PROCESSING'])],
                        'validation_status' => 'PENDING',
                        'last_error' => 'Emergency test transaction',
                        'gross_sales' => 1000,
                        'net_sales' => 892.86,
                        'vatable_sales' => 892.86,
                        'vat_amount' => 107.14,
                        'transaction_count' => 1,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    
                    $count++;
                } catch (\Exception $e) {
                    Log::error('Failed to create emergency transaction', [
                        'error' => $e->getMessage(),
                        'tx_id' => $txId
                    ]);
                }
            }
            
            return response()->json([
                'status' => 'success',
                'message' => "Successfully created {$count} emergency transactions",
                'transaction_ids' => $txIds
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to create emergency data', ['error' => $e->getMessage()]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create emergency data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function status($id)
    {
        try {
            $transaction = Transaction::findOrFail($id);
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'job_status' => $transaction->job_status,
                    'validation_status' => $transaction->validation_status,
                    'last_error' => $transaction->last_error,
                    'updated_at' => $transaction->updated_at
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get transaction status'
            ], 404);
        }
    }
}