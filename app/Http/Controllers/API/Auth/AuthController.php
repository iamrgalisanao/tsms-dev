<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // Find the user
        $user = User::where('email', $request->email)->first();
        
        if (!$user || !Hash::check($request->password, $user->password)) {
            Log::error('Login failed: Invalid credentials', ['email' => $request->email]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        // Check if user is active
        if (!$user->is_active) {
            Log::error('Login failed: User inactive', ['email' => $request->email]);
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive'
            ], 401);
        }

        try {
            // Create API token for SPA
            $token = $user->createToken('auth-token')->plainTextToken;
            
            // Also create a web session for the user if we're using a session
            if ($request->wantsJson()) {
                // API request - just return token
            } else {
                // Web request - create session
                Auth::login($user);
                $request->session()->regenerate();
            }
            
            // Update last login time
            $user->last_login_at = now();
            $user->save();
            
            Log::info('Login successful', ['email' => $request->email, 'user_id' => $user->id]);
            
            // Return both the token and intended redirect URL
            return response()->json([
                'success' => true,
                'token' => $token,
                'user' => $user->toArray(),
                'redirect_url' => '/dashboard'
            ]);
        } catch (\Exception $e) {
            Log::error('Authentication error', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Authentication error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }
}