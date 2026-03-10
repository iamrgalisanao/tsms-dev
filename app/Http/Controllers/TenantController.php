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
}
