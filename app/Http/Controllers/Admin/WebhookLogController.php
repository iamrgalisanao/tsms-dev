<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\WebhookLog;

class WebhookLogController extends Controller
{
    public function index(Request $request)
    {
        // Fetch and return webhook logs
        // You can implement pagination, filtering, etc. as needed
        $query = WebhookLog::query();

    if ($request->filled('status')) {
        $query->where('status', $request->status);
    }

    if ($request->filled('terminal_id')) {
        $query->where('terminal_id', $request->terminal_id);
    }

    if ($request->filled('http_code')) {
        $query->where('http_code', $request->http_code);
    }

    if ($request->filled('date_from')) {
        $query->whereDate('created_at', '>=', $request->date_from);
    }

    if ($request->filled('date_to')) {
        $query->whereDate('created_at', '<=', $request->date_to);
    }

        $webhookLogs = WebhookLog::orderBy('created_at', 'desc')->paginate(10);

        return response()->json([
            'data' => $webhookLogs->items(),
            'meta' => [
                'total' => $webhookLogs->total(),
                'current_page' => $webhookLogs->currentPage(),
                'per_page' => $webhookLogs->perPage(),
            ]
        ]);

        
    }
}
