<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use Illuminate\Http\Request;

class RetryHistoryController extends Controller
{
    public function index(Request $request)
    {
        $query = IntegrationLog::with(['terminal:id,terminal_id', 'tenant:id,name'])
            ->whereNotNull('retry_count')
            ->where('retry_count', '>', 0)
            ->select([
                'id',
                'transaction_id',
                'terminal_id',
                'status',
                'retry_count',
                'retry_reason',
                'created_at',
                'updated_at'
            ]);

        $paginator = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total()
            ]
        ]);
    }
}
