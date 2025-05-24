<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use App\Models\Transaction;
use App\Services\PdfExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;

class LogViewerController extends Controller
{
    protected $pdfExportService;
    
    public function __construct(PdfExportService $pdfExportService)
    {
        $this->pdfExportService = $pdfExportService;
    }
    
    public function index(Request $request)
    {
        // Build query with eager loading
        $query = SystemLog::with('terminal')
            ->when($request->type, function($q) use ($request) {
                $q->where('type', $request->type);
            })
            ->when($request->severity, function($q) use ($request) {
                $q->where('severity', $request->severity);
            })
            ->when($request->search, function($q) use ($request) {
                $q->where(function($sq) use ($request) {
                    $sq->where('message', 'like', "%{$request->search}%")
                       ->orWhere('transaction_id', 'like', "%{$request->search}%")
                       ->orWhere('terminal_uid', 'like', "%{$request->search}%");
                });
            });

        // Get stats first
        $stats = [
            'transactions' => SystemLog::where('type', 'transaction')->count(),
            'errors' => SystemLog::where('severity', 'error')->count(),
            'warnings' => SystemLog::where('severity', 'warning')->count(),
            'info' => SystemLog::where('severity', 'info')->count(),
            'latest_error' => SystemLog::where('severity', 'error')
                ->latest()
                ->first()?->created_at?->format('Y-m-d H:i:s'),
            'logs_today' => SystemLog::whereDate('created_at', today())->count()
        ];

        // Get paginated logs
        $logs = $query->latest()->paginate(10);

        return view('dashboard.system-logs', compact('logs', 'stats'));
    }

    protected function getTypeClass($type)
    {
        return match($type) {
            'transaction' => 'info',
            'system' => 'primary',
            'auth' => 'warning',
            default => 'secondary'
        };
    }

    protected function getSeverityClass($severity)
    {
        return match($severity) {
            'error' => 'danger',
            'warning' => 'warning',
            'info' => 'info',
            default => 'secondary'
        };
    }
    
    public function show($id)
    {
        $log = SystemLog::with(['terminal:id,terminal_uid'])->findOrFail($id);
        return view('dashboard.log-detail', compact('log'));
    }
    
    public function export(Request $request)
    {
        try {
            // Validate export parameters
            $validated = $request->validate([
                'format' => 'required|in:csv,pdf',
                'log_type' => 'nullable|string',
                'terminal_id' => 'nullable|exists:pos_terminals,id',
                'user_id' => 'nullable|exists:users,id',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'severity' => 'nullable|string'
            ]);
            
            // Build query based on filters
            $query = $this->buildFilteredQuery($request);
            
            // Handle based on requested format
            if ($request->format === 'csv') {
                return $this->exportToCsv($query, $request->all());
            } else {
                return $this->exportToPdf($query, $request->all());
            }
        } catch (\Exception $e) {
            Log::error('Error exporting logs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()
                ->route('dashboard.log-viewer')
                ->with('error', 'Failed to export logs: ' . $e->getMessage());
        }
    }
    
    /**
     * Build the query with filters applied
     */
    private function buildFilteredQuery(Request $request)
    {
        // Start with basic query
        $query = IntegrationLog::query();
        
        // Only add relations for columns that exist
        if (Schema::hasColumn('integration_logs', 'terminal_id')) {
            $query->with('posTerminal:id,terminal_uid');
        }
        
        if (Schema::hasColumn('integration_logs', 'tenant_id')) {
            $query->with('tenant:id,name');
        }
        
        if (Schema::hasColumn('integration_logs', 'user_id')) {
            $query->with('user:id,name,email');
        }
        
        // Apply log type filter if the column exists
        if ($request->has('log_type') && $request->log_type !== 'all' && Schema::hasColumn('integration_logs', 'log_type')) {
            $query->where('log_type', $request->log_type);
        }
        
        // Apply terminal filter
        if ($request->has('terminal_id') && !empty($request->terminal_id) && Schema::hasColumn('integration_logs', 'terminal_id')) {
            $query->where('terminal_id', $request->terminal_id);
        }
        
        // Apply user filter (admin only) if the column exists
        if ($request->has('user_id') && !empty($request->user_id) && Schema::hasColumn('integration_logs', 'user_id')) {
            $user = Auth::user();
            $isAdmin = false;
            
            // Check if we're using Spatie Permission package
            if (method_exists($user, 'hasRole')) {
                $isAdmin = $user->hasRole('admin');
            }
            // Alternatively check if the user has admin in their roles relationship
            else if (method_exists($user, 'roles') && $user->roles) {
                $isAdmin = $user->roles->contains('name', 'admin');
            }
            // Fallback to checking a simple is_admin field if it exists
            else if (isset($user->is_admin)) {
                $isAdmin = (bool)$user->is_admin;
            }
            
            if ($isAdmin) {
                $query->where('user_id', $request->user_id);
            }
        }
        
        // Apply date filters
        if ($request->has('date_from') && !empty($request->date_from)) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        
        if ($request->has('date_to') && !empty($request->date_to)) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        
        // Apply severity filter if the column exists
        if ($request->has('severity') && !empty($request->severity) && Schema::hasColumn('integration_logs', 'severity')) {
            $query->where('severity', $request->severity);
        }
        
        return $query;
    }
    
    /**
     * Export logs to CSV format
     */
    private function exportToCsv($query, array $filters)
    {
        $logs = $query->latest()->get();
        
        $filename = 'logs_export_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];
        
        $callback = function() use ($logs) {
            $file = fopen('php://output', 'w');
            
            // Add headers
            fputcsv($file, [
                'ID', 'Log Type', 'Terminal', 'Message', 'Severity', 
                'Transaction ID', 'User', 'Tenant', 'Created At'
            ]);
            
            // Add data rows
            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->id,
                    $log->log_type ?? 'N/A',
                    $log->posTerminal->terminal_uid ?? 'N/A',
                    $log->message ?? 'N/A',
                    $log->severity ?? 'N/A',
                    $log->transaction_id ?? 'N/A',
                    $log->user ? ($log->user->name . ' (' . $log->user->email . ')') : 'N/A',
                    $log->tenant->name ?? 'N/A',
                    $log->created_at->format('Y-m-d H:i:s')
                ]);
            }
            
            fclose($file);
        };
        
        return response()->stream($callback, 200, $headers);
    }
    
    /**
     * Export logs to PDF format
     */
    private function exportToPdf($query, array $filters)
    {
        try {
            $logs = $query->latest()->limit(1000)->get();
            
            // Generate the PDF using the service
            $filename = 'logs_export_' . date('Y-m-d_H-i-s') . '.pdf';
            $path = $this->pdfExportService->generateLogsPdf($logs, $filters, $filename);
            
            // Convert storage path to URL
            $relativePath = str_replace(storage_path('app/public'), '', $path);
            $url = Storage::disk('public')->url(ltrim($relativePath, '/'));
            
            // Return download response
            return response()->download($path, $filename, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Error exporting PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()
                ->route('dashboard.log-viewer')
                ->with('error', 'PDF export failed: ' . $e->getMessage());
        }
    }
}