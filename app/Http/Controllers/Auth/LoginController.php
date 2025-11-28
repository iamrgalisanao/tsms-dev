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

            // Redirect commercial role users to the commercial charts index.
            // Finance users should land on the finance reports dashboard (/reports).
            $isCommercial = false;
            $isFinance = false;
            if ($user && method_exists($user, 'hasRole')) {
                $isCommercial = $user->hasRole('commercial');
                $isFinance = $user->hasRole('finance');
            } elseif ($user && isset($user->role)) {
                $isCommercial = $user->role === 'commercial';
                $isFinance = $user->role === 'finance';
            }

            // Priority: commercial -> /commercial, finance -> /reports, otherwise admin dashboard
            if ($isCommercial) {
                $default = '/commercial';
            } elseif ($isFinance) {
                $default = '/reports';
            } else {
                $default = '/dashboard';
            }
            return redirect()->intended($default);
        }

        Log::warning('Failed login attempt', ['email' => $request->email]);
        
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
        
        return redirect('/login');
    }
}