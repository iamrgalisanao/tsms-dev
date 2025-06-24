<?php

namespace App\Services;

use App\Models\IntegrationLog;
use App\Models\PosTerminal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class RetryHistoryService
{
    /**
     * Generate sample data if no real data exists yet
     * 
     * @param array $filters Optional filters to apply to sample data
     * @return array
     */
    public function getSampleData(array $filters = [])
    {
        // Check if we have any real data
        $hasRealData = IntegrationLog::whereNotNull('retry_count')
            ->where('retry_count', '>', 0)
            ->exists();
            
        if ($hasRealData) {
            return null;
        }
        
        Log::info('Generating sample retry history data with filters', $filters ?? []);
        
        // Generate sample data
        $transactions = [];
        $statuses = ['SUCCESS', 'FAILED', 'PENDING'];
        $reasons = [
            'Network timeout',
            'Invalid transaction format',
            'Service unavailable',
            'Authentication failed',
            'Database connection error'
        ];
        
        // Get real terminals if available, or create fake ones
        $terminals = PosTerminal::select('id', 'terminal_uid')->get();
        if ($terminals->isEmpty()) {
            $terminalIds = [1, 2, 3];
            $terminalUids = ['TERM-001', 'TERM-002', 'TERM-003'];
        } else {
            $terminalIds = $terminals->pluck('id')->toArray();
            $terminalUids = $terminals->pluck('terminal_uid')->toArray();
        }
        
        for ($i = 1; $i <= 10; $i++) {
            $terminalIndex = array_rand($terminalIds);
            
            // Apply status filter if provided
            $status = isset($filters['status']) && !empty($filters['status']) 
                ? $filters['status'] 
                : $statuses[array_rand($statuses)];
                
            $retry_success = $status === 'SUCCESS';
            $retry_count = rand(1, 5);
            
            // Apply terminal filter if provided
            if (isset($filters['terminal_id']) && !empty($filters['terminal_id']) 
                && $terminalIds[$terminalIndex] != $filters['terminal_id']) {
                continue; // Skip this item if it doesn't match the terminal filter
            }
            
            $createdAt = Carbon::now()->subDays(rand(1, 30));
            
            // Apply date filters if provided
            if (isset($filters['date_from']) && !empty($filters['date_from']) 
                && $createdAt->format('Y-m-d') < $filters['date_from']) {
                continue; // Skip if before date_from
            }
            
            if (isset($filters['date_to']) && !empty($filters['date_to']) 
                && $createdAt->format('Y-m-d') > $filters['date_to']) {
                continue; // Skip if after date_to
            }
            
            $transactions[] = [
                'id' => $i,
                'transaction_id' => 'TX-' . str_pad($i, 6, '0', STR_PAD_LEFT),
                'terminal_id' => $terminalIds[$terminalIndex],
                'posTerminal' => [
                    'terminal_uid' => $terminalUids[$terminalIndex] ?? 'TERM-' . str_pad($terminalIndex + 1, 3, '0', STR_PAD_LEFT)
                ],
                'status' => $status,
                'retry_count' => $retry_count,
                'retry_reason' => $reasons[array_rand($reasons)],
                'response_time' => rand(100, 5000) / 10,
                'retry_success' => $retry_success,
                'last_retry_at' => Carbon::now()->subHours(rand(1, 48))->toDateTimeString(),
                'created_at' => $createdAt->toDateTimeString(),
                'updated_at' => Carbon::now()->subHours(rand(1, 24))->toDateTimeString(),
            ];
        }
        
        // If we have no transactions after filtering, return at least one that matches the filters
        if (empty($transactions) && isset($filters['status']) && !empty($filters['status'])) {
            $terminalIndex = array_rand($terminalIds);
            $transactions[] = [
                'id' => 1,
                'transaction_id' => 'TX-000001',
                'terminal_id' => $terminalIds[$terminalIndex],
                'posTerminal' => [
                    'terminal_uid' => $terminalUids[$terminalIndex] ?? 'TERM-001'
                ],
                'status' => $filters['status'],
                'retry_count' => rand(1, 5),
                'retry_reason' => $reasons[array_rand($reasons)],
                'response_time' => rand(100, 5000) / 10,
                'retry_success' => $filters['status'] === 'SUCCESS',
                'last_retry_at' => Carbon::now()->subHours(rand(1, 48))->toDateTimeString(),
                'created_at' => Carbon::now()->subDays(rand(1, 30))->toDateTimeString(),
                'updated_at' => Carbon::now()->subHours(rand(1, 24))->toDateTimeString(),
            ];
        }
        
        // Calculate analytics based on filtered sample data
        $totalRetries = array_sum(array_column($transactions, 'retry_count'));
        $successCount = count(array_filter($transactions, function($item) {
            return $item['status'] === 'SUCCESS';
        }));
        $successRate = count($transactions) > 0 ? round(($successCount / count($transactions)) * 100) : 0;
        
        return [
            'data' => $transactions,
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => count($transactions),
                'total' => count($transactions)
            ],
            'analytics' => [
                'total_retries' => $totalRetries,
                'success_rate' => $successRate,
                'avg_response_time' => 250,
                'retries_by_terminal' => []
            ]
        ];
    }

    
}