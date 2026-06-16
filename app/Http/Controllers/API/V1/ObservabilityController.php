<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Services\DuplicateReceiptMonitorService;
use App\Services\TenantIngestionAuditService;
use App\Support\Metrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ObservabilityController extends Controller
{
    /**
     * Get real-time snapshot of the intake pipeline health.
     */
    public function index(): JsonResponse
    {
        $metrics = Metrics::snapshot([
            'intake.received_count',
            'intake.accepted_count',
            'intake.rejected_count',
            'intake.processed_count',
            'intake.failed_count',
        ]);

        $latencies = [
            'dispatch_avg_ms' => Metrics::get('intake.dispatch_latency:avg', 0),
            'processing_lag_avg_s' => Metrics::get('intake.processing_lag:avg', 0),
            'worker_time_avg_ms' => Metrics::get('intake.worker_time:avg', 0),
        ];

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            'metrics' => $metrics,
            'latencies' => $latencies,
            'queue_size' => null,
        ]);
    }

    /**
     * Get historical bucketed data for charting.
     */
    public function history(Request $request): JsonResponse
    {
        $minutes = $request->query('minutes', 60);
        $metric = $request->query('metric', 'intake.processing_lag');
        
        $history = [];
        $now = now();

        for ($i = $minutes; $i >= 0; $i--) {
            $time = $now->copy()->subMinutes($i)->format('Y-m-d H:i');
            $key = "metrics:buckets:{$metric}:{$time}";
            
            $history[] = [
                'time' => $time,
                'value' => (float) Cache::get($key, 0),
            ];
        }

        return response()->json([
            'success' => true,
            'metric' => $metric,
            'data' => $history,
        ]);
    }

    /**
     * Get tenant volume breakdown.
     */
    public function tenants(): JsonResponse
    {
        // In a real system, we'd iterate over active tenants.
        // For now, we store them in a specific set or just query them.
        // We'll return the top ones from our Metrics helper if we had a list.
        // For simplicity, we'll return a stub or query recent intake.
        
        // Let's grab the top 10 from the database if cache isn't available
        $topTenants = \App\Models\TransactionIntake::select('tenant_id', \DB::raw('count(*) as count'))
            ->where('received_at', '>=', now()->subHours(24))
            ->groupBy('tenant_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $topTenants,
        ]);
    }

    /**
     * Get recent ingestion diagnostic logs.
     */
    /**
     * Get recent ingestion diagnostic logs.
     */
    public function recent(): JsonResponse
    {
        $recent = \App\Models\TransactionIntake::select([
                'id', 
                'payload',
                'tenant_id',
                'terminal_id', 
                'processing_status', 
                'last_error_message', 
                'processed_at', 
                'received_at'
            ])
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(function ($intake) {
                $payload = is_array($intake->payload) ? $intake->payload : [];
                $payloadPreview = [
                    'submission_uuid' => $payload['submission_uuid'] ?? null,
                    'tenant_id' => $payload['tenant_id'] ?? $intake->tenant_id,
                    'terminal_id' => $payload['terminal_id'] ?? $intake->terminal_id,
                    'transaction_count' => $payload['transaction_count'] ?? null,
                    'transaction' => [
                        'transaction_id' => data_get($payload, 'transaction.transaction_id'),
                        'receipt_no' => data_get($payload, 'transaction.receipt_no'),
                        'hardware_id' => data_get($payload, 'transaction.hardware_id'),
                    ],
                ];

                return [
                    'id' => $intake->id,
                    'receipt_no' => data_get($payload, 'transaction.receipt_no') ?? $payload['receipt_no'] ?? '---',
                    'terminal_id' => $intake->terminal_id,
                    'payload' => $payloadPreview,
                    'processing_status' => strtolower($intake->processing_status ?? 'pending'),
                    'last_error_message' => $intake->last_error_message,
                    'processed_at' => $intake->processed_at,
                    'received_at' => $intake->received_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $recent,
        ]);
    }

    public function duplicateReceipts(Request $request, DuplicateReceiptMonitorService $monitor): JsonResponse
    {
        $report = $monitor->report([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'tenant' => $request->query('tenant'),
            'terminal' => $request->query('terminal'),
            'limit' => $request->query('limit', 100),
        ]);

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            ...$report,
        ]);
    }

    public function tenantIngestionAudit(Request $request, TenantIngestionAuditService $audit): JsonResponse
    {
        $report = $audit->report([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'tenant' => $request->query('tenant'),
            'terminal' => $request->query('terminal'),
            'limit' => $request->query('limit', 200),
            'only_issues' => $request->boolean('only_issues'),
        ]);

        return response()->json([
            'success' => true,
            'timestamp' => now()->toIso8601String(),
            ...$report,
        ]);
    }
}
