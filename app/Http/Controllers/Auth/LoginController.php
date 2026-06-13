<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user = \App\Models\User::find(Auth::id());
            if ($user) {
                $user->last_login_at = now();
                $user->save();
            }

            Log::info('User logged in successfully', ['email' => $request->email]);

            $roles = $user->getRoleNames()->values()->toArray();
            $redirectUrl = '/dashboard';
            if (in_array('finance', $roles, true)) {
                $redirectUrl = '/finance';
            } elseif (in_array('commercial', $roles, true)) {
                $redirectUrl = '/commercial';
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'user' => array_merge(
                        $user->toArray(),
                        ['roles' => $roles]
                    ),
                    'redirect_url' => $redirectUrl,
                ]);
            }
            
            return redirect()->intended($redirectUrl);
        }

        Log::warning('Failed login attempt', ['email' => $request->email]);
        
        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'The provided credentials do not match our records.',
            ], 422);
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->withInput($request->except('password'));
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        
        Auth::logout();
        
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        // Log the logout event
        Log::info('User logged out', [
            'user_id' => $user?->id,
            'email' => $user?->email,
            'ip_address' => $request->ip()
        ]);

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }
        
        return redirect('/login');
    }
}
