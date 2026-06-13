<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class TenantUserController extends Controller
{
    public function index(Tenant $tenant)
    {
        return $tenant->users()->orderBy('name')->get();
    }

    public function store(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
        ]);

        $user = $tenant->users()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        // Default role for tenant users if applicable
        // $user->assignRole('tenant-user');

        return response()->json($user, 201);
    }

    public function destroy(Tenant $tenant, User $user)
    {
        if ($user->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'User does not belong to this tenant'], 403);
        }

        $user->delete();
        return response()->json(null, 24);
    }
}
