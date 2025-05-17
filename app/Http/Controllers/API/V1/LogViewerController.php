<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log as LogFacade;
use Illuminate\Support\Facades\Schema;

class LogViewerController extends Controller
{
    public function index(Request $request)
    {
        try {
            // Create a base query with models to eager load
            $query = IntegrationLog::with(['posTerminal:id,terminal_uid', 'tenant:id,name']);
            
            // Conditionally add user relation if that column exists
            if (Schema::hasColumn('integration_logs', 'user_id')) {
                $query->with('user:id,name,email');
            }
            
            // Select only columns that exist in the database
            $selectColumns = ['id', 'transaction_id', 'terminal_id', 'tenant_id', 'status', 
                'retry_count', 'retry_reason', 'response_time',
                'http_status_code', 'error_message', 'created_at', 'updated_at'];
                
            // Add optional columns if they exist
            if (Schema::hasColumn('integration_logs', 'log_type')) {
                $selectColumns[] = 'log_type';
            }
            
            if (Schema::hasColumn('integration_logs', 'severity')) {
                $selectColumns[] = 'severity';
            }
            
            if (Schema::hasColumn('integration_logs', 'message')) {
                $selectColumns[] = 'message';
            }
            
            if (Schema::hasColumn('integration_logs', 'user_id')) {
                $selectColumns[] = 'user_id';
            }
            
            $query->select($selectColumns);

            // Apply filters based on request and schema compatibility
            if ($request->has('log_type') && $request->log_type !== 'all' && Schema::hasColumn('integration_logs', 'log_type')) {
                $query->where('log_type', $request->log_type);
            }

            if ($request->has('terminal_id') && !empty($request->terminal_id)) {
                $query->where('terminal_id', $request->terminal_id);
            }
            
            if ($request->has('severity') && !empty($request->severity) && Schema::hasColumn('integration_logs', 'severity')) {
                $query->where('severity', $request->severity);
            }
            
            if ($request->has('user_id') && !empty($request->user_id) && Schema::hasColumn('integration_logs', 'user_id')) {
                // Check if user has admin permissions
                $user = Auth::user();
                $isAdmin = false;
                
                if (method_exists($user, 'hasRole')) {
                    $isAdmin = $user->hasRole('admin');
                } elseif (isset($user->is_admin)) {
                    $isAdmin = (bool)$user->is_admin;
                }
                
                if ($isAdmin) {
                    $query->where('user_id', $request->user_id);
                }
            }
            
            if ($request->has('date_from') && !empty($request->date_from)) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            
            if ($request->has('date_to') && !empty($request->date_to)) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            // Apply search if applicable
            if ($request->has('search') && !empty($request->search)) {
                $query->where(function($q) use ($request) {
                    // Search in transaction_id
                    $q->where('transaction_id', 'like', '%' . $request->search . '%');
                    
                    // Search in message if it exists
                    if (Schema::hasColumn('integration_logs', 'message')) {
                        $q->orWhere('message', 'like', '%' . $request->search . '%');
                    }
                    
                    // Search in error_message
                    $q->orWhere('error_message', 'like', '%' . $request->search . '%');
                });
            }

            // Fetch paginated results
            $paginator = $query->latest()->paginate($request->input('per_page', 10));
            
            // Build statistics based on available columns
            $stats = [
                'total_logs' => IntegrationLog::count(),
                'logs_today' => IntegrationLog::whereDate('created_at', now()->toDateString())->count()
            ];
            
            // Add error counts if severity column exists
            if (Schema::hasColumn('integration_logs', 'severity')) {
                $stats['error_count'] = IntegrationLog::where('severity', 'error')->count();
                $stats['warning_count'] = IntegrationLog::where('severity', 'warning')->count();
                $stats['info_count'] = IntegrationLog::where('severity', 'info')->count();
            } else {
                // Fallback to status if severity doesn't exist
                $stats['error_count'] = IntegrationLog::where('status', 'FAILED')->count();
                $stats['success_count'] = IntegrationLog::where('status', 'SUCCESS')->count();
            }

            return response()->json([
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total()
                ],
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            LogFacade::error('Error fetching logs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 10,
                    'total' => 0
                ],
                'stats' => [
                    'total_logs' => 0,
                    'error_count' => 0,
                    'warning_count' => 0,
                    'info_count' => 0,
                    'logs_today' => 0
                ],
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Apply advanced search filters based on field, operator and value
     */
    private function applyAdvancedSearch($query, $field, $operator, $value)
    {
        // Search across all fields
        if ($field === 'all') {
            return $query->where(function($q) use ($operator, $value) {
                $this->applyOperator($q, 'message', $operator, $value, 'or');
                $this->applyOperator($q, 'transaction_id', $operator, $value, 'or');
                $this->applyOperator($q, 'context', $operator, $value, 'or');
            });
        }
        
        // Search specific field
        return $this->applyOperator($query, $field, $operator, $value);
    }
    
    /**
     * Apply search operator to a field
     */
    private function applyOperator($query, $field, $operator, $value, $boolean = 'and')
    {
        switch ($operator) {
            case 'equals':
                return $query->where($field, '=', $value, $boolean);
                
            case 'starts_with':
                return $query->where($field, 'like', $value . '%', $boolean);
                
            case 'ends_with':
                return $query->where($field, 'like', '%' . $value, $boolean);
                
            case 'regex':
                try {
                    // Make sure value is a valid regex
                    if (@preg_match($value, '') !== false) {
                        return $query->whereRaw("$field REGEXP ?", [$value], $boolean);
                    }
                } catch (\Exception $e) {
                    LogFacade::warning('Invalid regex in search', ['regex' => $value]);
                }
                // Fall back to contains if regex is invalid
                return $query->where($field, 'like', '%' . $value . '%', $boolean);
                
            case 'contains':
            default:
                return $query->where($field, 'like', '%' . $value . '%', $boolean);
        }
    }
    
    public function getLogTypes()
    {
        try {
            // Get all unique log types
            $logTypes = IntegrationLog::distinct()
                ->pluck('log_type')
                ->filter()
                ->values();
                
            return response()->json([
                'log_types' => $logTypes
            ]);
        } catch (\Exception $e) {
            LogFacade::error('Error getting log types', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'log_types' => []
            ]);
        }
    }
    
    public function getSeverities()
    {
        try {
            // Get all unique severities
            $severities = IntegrationLog::distinct()
                ->pluck('severity')
                ->filter()
                ->values();
                
            return response()->json([
                'severities' => $severities
            ]);
        } catch (\Exception $e) {
            LogFacade::error('Error getting severities', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'severities' => []
            ]);
        }
    }
    
    /**
     * Stream logs in real-time
     */
    public function streamLogs(Request $request)
    {
        return response()->stream(function () use ($request) {
            // Set headers for SSE
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable Nginx buffering
            
            // Keep track of the last log ID we've sent
            $lastLogId = IntegrationLog::latest()->first()->id ?? 0;
            
            // Keep the connection open and stream logs as they come in
            while (true) {
                // Apply filters from the request
                $query = IntegrationLog::with(['posTerminal:id,terminal_uid', 'tenant:id,name']);
                
                // Conditionally add user relation if that column exists
                if (Schema::hasColumn('integration_logs', 'user_id')) {
                    $query->with('user:id,name,email');
                }
                
                $query->where('id', '>', $lastLogId);
                
                // Apply log type filter
                if ($request->has('log_type') && $request->log_type !== 'all' && Schema::hasColumn('integration_logs', 'log_type')) {
                    $query->where('log_type', $request->log_type);
                }
                
                // Apply other filters
                // ...

                // Get new logs
                $newLogs = $query->latest()->limit(10)->get();
                
                if ($newLogs->count() > 0) {
                    // Update the last log ID
                    $lastLogId = $newLogs->first()->id;
                    
                    // Send the logs to the client
                    echo "event: logs\n";
                    echo "data: " . json_encode(['logs' => $newLogs]) . "\n\n";
                    
                    // Flush the output buffer to send the data immediately
                    ob_flush();
                    flush();
                }
                
                // Wait before checking for new logs
                sleep(3);
                
                // Send a ping event to keep the connection alive
                echo "event: ping\n";
                echo "data: " . json_encode(['time' => time()]) . "\n\n";
                ob_flush();
                flush();
                
                // Handle client disconnection
                if (connection_aborted()) {
                    break;
                }
            }
        }, 200, [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/event-stream',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}