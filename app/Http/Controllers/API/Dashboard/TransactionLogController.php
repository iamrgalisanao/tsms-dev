<?php


namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\TransactionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionLogController extends Controller
{
    public function index(Request $request)
    {
    $query = TransactionLog::with(['terminal', 'customer'])
            ->when($request->terminal_id, function($q) use ($request) {
                return $q->where('terminal_id', $request->terminal_id);
            })
            ->when($request->status, function($q) use ($request) {
                return $q->where('status', $request->status);
            })
            ->when($request->date_from, function($q) use ($request) {
                return $q->where('created_at', '>=', $request->date_from);
            })
            ->when($request->date_to, function($q) use ($request) {
                return $q->where('created_at', '<=', $request->date_to);
            })
            ->latest();

        $logs = $query->paginate($request->per_page ?? 15);

        return response()->json($logs);
    }

    public function show($id)
    {
        $log = TransactionLog::with('terminal')->findOrFail($id);
        return response()->json($log);
    }
}