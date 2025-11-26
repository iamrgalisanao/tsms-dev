<?php

namespace App\Http\Controllers\Api\Webapp;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Reports\HourlyReportService;

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

        return response()->json(['data' => $data]);
    }
}
