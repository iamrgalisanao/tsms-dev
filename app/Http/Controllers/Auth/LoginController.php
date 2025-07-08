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
            
            return redirect()->intended('/dashboard');
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