<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use App\Models\PosTerminal;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // Define your policies here
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Define gate for admin access
        Gate::define('admin', function ($user) {
            return $user->role === 'admin';
        });

        // Horizon dashboard access gate
        Gate::define('viewHorizon', function ($user) {
            // Support either role column or spatie/permission roles
            if (method_exists($user, 'hasRole')) {
                return $user->hasRole('admin') || $user->hasRole('ops');
            }
            return in_array($user->role ?? null, ['admin','ops']);
        });

        // Register the 'api' guard to use JWT for terminal authentication
        Auth::viaRequest('api', function ($request) {
            // Check for the bearer token
            $token = $request->bearerToken();
            
            if (!$token) {
                return null;
            }
            
            try {
                // Validate JWT token and get terminal
                // For testing, we can use a simple validation to get it working
                $terminal = PosTerminal::where('jwt_token', $token)->first();
                
                return $terminal;
            } catch (\Exception $e) {
                // Log the error but don't expose details
                \Illuminate\Support\Facades\Log::error('API auth error', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }
}