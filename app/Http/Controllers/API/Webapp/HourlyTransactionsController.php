<?php
namespace App\Http\Controllers\API\Webapp;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Reports\HourlyReportService;
use App\Models\AuditLog;

class HourlyTransactionsController extends Controller
{
    /**
     * GET /api/v1/webapp/transactions/hourly
     *
     * Query params:
     *  - tenant_id (optional)
     *  - terminal_id (optional)
     *  - date_from (required, Y-m-d)
     *  - date_to (required, Y-m-d)
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'tenant_id'   => ['nullable', 'string', 'max:64'],
            'terminal_id' => ['nullable', 'string', 'max:64'],
            'date_from'   => ['required', 'date'],
            'date_to'     => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $dateFrom = $validated['date_from'];
        $dateTo = $validated['date_to'];

        // Delegate to the service
        $service = new HourlyReportService();
        $data = $service->getHourlyAggregates($dateFrom, $dateTo, $validated['tenant_id'] ?? null, $validated['terminal_id'] ?? null);

        // Record API access for reporting views (non-blocking)
        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'report.hourly_api_view',
                'action_type' => 'view',
                'resource_type' => 'report',
                'resource_id' => null,
                'ip_address' => $request->ip(),
                'message' => sprintf('API hourly report viewed (%s to %s)', $dateFrom, $dateTo),
                'old_values' => null,
                'new_values' => null,
                'metadata' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'tenant_id' => $validated['tenant_id'] ?? null,
                    'terminal_id' => $validated['terminal_id'] ?? null,
                    'user_agent' => $request->header('User-Agent'),
                ],
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write AuditLog for hourly API report view: ' . $e->getMessage(), ['date_from' => $dateFrom, 'date_to' => $dateTo]);
        }

        return response()->json(['data' => $data]);
    }
}
