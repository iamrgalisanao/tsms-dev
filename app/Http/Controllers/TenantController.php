<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantController extends Controller
{
    public function index()
    {
        return Tenant::withCount('users')
            ->withCount('posTerminals')
            ->orderBy('trade_name')
            ->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'trade_name' => 'required|string|max:255',
            'customer_code' => 'required|string|max:50|unique:tenants,customer_code',
            'company_id' => 'required|exists:companies,id',
            'location_type' => 'nullable|string',
            'location' => 'nullable|string',
            'unit_no' => 'nullable|string',
            'category' => 'nullable|string',
            'status' => 'required|string|in:Operational,Closed,Pending',
        ]);

        // UUID is generated server-side, do not accept from payload
        unset($validated['uuid']);

        $tenant = Tenant::create($validated);

        return response()->json($tenant, 201);
    }

    public function show(Tenant $tenant)
    {
        return $tenant->load(['users', 'posTerminals']);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'trade_name' => 'sometimes|required|string|max:255',
            'customer_code' => 'sometimes|required|string|max:50|unique:tenants,customer_code,' . $tenant->id,
            'company_id' => 'sometimes|required|exists:companies,id',
            'location_type' => 'nullable|string',
            'location' => 'nullable|string',
            'unit_no' => 'nullable|string',
            'category' => 'nullable|string',
            'status' => 'sometimes|required|string|in:Operational,Closed,Pending',
        ]);

        $tenant->update($validated);

        return response()->json($tenant);
    }

    public function destroy(Tenant $tenant)
    {
        $tenant->delete();
        return response()->json(null, 24);
    }

    /**
     * Export all tenants to CSV (seeder-compatible format)
     */
    public function export(Request $request)
    {
        try {
            $filename = 'tenants_export_' . now()->format('Ymd_His') . '.csv';
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Pragma' => 'no-cache',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0'
            ];

            $callback = function() {
                $handle = fopen('php://output', 'w');
                // Header row with all seeder-relevant columns
                fputcsv($handle, [
                    'id',
                    'company_id',
                    'customer_code',
                    'trade_name',
                    'location_type',
                    'location',
                    'unit_no',
                    'floor_area',
                    'status',
                    'accept_with_issues',
                    'activity_monitoring_enabled',
                    'activity_threshold_minutes',
                    'activity_monitoring_notes',
                    'category',
                    'zone',
                    'uuid',
                    'created_at',
                    'updated_at'
                ]);

                // Stream the tenants chunk by chunk
                Tenant::orderBy('id')->chunk(200, function($tenants) use($handle) {
                    foreach ($tenants as $tenant) {
                        fputcsv($handle, [
                            $tenant->id,
                            $tenant->company_id,
                            $tenant->customer_code,
                            $tenant->trade_name,
                            $tenant->location_type,
                            $tenant->location,
                            $tenant->unit_no,
                            $tenant->floor_area,
                            $tenant->status,
                            $tenant->accept_with_issues ? 1 : 0,
                            $tenant->activity_monitoring_enabled ? 1 : 0,
                            $tenant->activity_threshold_minutes,
                            $tenant->activity_monitoring_notes,
                            $tenant->category,
                            $tenant->zone,
                            $tenant->uuid,
                            $tenant->created_at ? $tenant->created_at->toISOString() : null,
                            $tenant->updated_at ? $tenant->updated_at->toISOString() : null
                        ]);
                    }
                });

                fclose($handle);
            };

            return response()->stream($callback, 200, $headers);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error exporting tenants', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error exporting tenants: ' . $e->getMessage()
            ], 500);
        }
    }
}
