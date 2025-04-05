<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\PosTerminal;

class TerminalAuthController extends Controller
{
    public function register(Request $request)
    {

    
       try{
        $request->merge([
            'tenant_code' => trim(strtoupper($request->tenant_code)),
        ]);
        $request->validate([
            'tenant_code' =>  'required|string|max:255',
            'terminal_uid' => 'required|string|max:255',
        ]);

        $tenant = Tenant::where('code', $request->tenant_code)->firstOrFail();

        $terminal = PosTerminal::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'terminal_uid' => $request->terminal_uid,
            ],
            [
                'registered_at' => now(),
                'status' => 'active',
            ]
        );

        $token = $terminal->createToken('POS Terminal')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'terminal_id' => $terminal->id
        ]);
       } catch(\Throwable $e){
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 500);
       }
        
    }
}

