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

            // Role-aware redirect
            $redirectUrl = '/dashboard';
            if ($user->hasRole('finance')) {
                $redirectUrl = '/finance';
            } elseif ($user->hasRole('commercial')) {
                $redirectUrl = '/commercial';
            }

            // Return both the token and intended redirect URL
            return response()->json([
                'success' => true,
                'token' => $token,
                'user' => array_merge($user->toArray(), [
                    'roles' => $user->getRoleNames()->values()->toArray(),
                ]),
                'redirect_url' => $redirectUrl
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
        $user = $request->user();

        try {
            // 1. Revoke the token if it's a Sanctum token-based request
            // We check if currentAccessToken() exists and is NOT a TransientToken
            // TransientTokens do not have a delete() method and are used for session-based auth.
            if ($user && method_exists($user, 'currentAccessToken')) {
                $token = $user->currentAccessToken();
                if ($token && method_exists($token, 'delete')) {
                    $token->delete();
                }
            }

            // 2. Explicitly log out from the web guard to clear session-based auth
            Auth::guard('web')->logout();

            // 3. Clear the session if it exists to prevent reuse
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            Log::info('Logout successful', ['user_id' => $user->id ?? 'unknown']);

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Logout error', [
                'user_id' => $user->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);

            // Even if revocation fails, we still want to indicate "success" to the frontend
            // so it can clear its own local storage and redirect.
            return response()->json([
                'success' => true,
                'message' => 'Logged out (with minor errors): ' . $e->getMessage()
            ]);
        }
    }

    public function user(Request $request)
    {
        $user = $request->user();
        return response()->json(array_merge($user->toArray(), [
            'roles' => $user->getRoleNames()->values()->toArray(),
        ]));
    }
}