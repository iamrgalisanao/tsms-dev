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

        // Grant all abilities to admin upfront (supports either role column or spatie roles)
        Gate::before(function ($user, $ability) {
            try {
                if (method_exists($user, 'hasRole')) {
                    return $user->hasRole('admin') ? true : null;
                }
                return (($user->role ?? null) === 'admin') ? true : null;
            } catch (\Throwable $e) {
                return null;
            }
        });

        // Define gate for admin access
        Gate::define('admin', function ($user) {
            return $user->role === 'admin';
        });

        // Gate for exporting transaction logs (admin or manager)
        Gate::define('export-transaction-logs', function ($user) {
            if (method_exists($user, 'hasRole')) {
                return $user->hasRole('admin') || $user->hasRole('manager');
            }
            return in_array($user->role ?? null, ['admin', 'manager'], true);
        });

        // Gate for retrying transactions (admin or manager)
        Gate::define('retry-transactions', function ($user) {
            if (method_exists($user, 'hasRole')) {
                return $user->hasRole('admin') || $user->hasRole('manager');
            }
            return in_array($user->role ?? null, ['admin', 'manager'], true);
        });

        // Horizon dashboard access gate
        Gate::define('viewHorizon', function ($user) {
            // Support either role column or spatie/permission roles
            if (method_exists($user, 'hasRole')) {
                return $user->hasRole('admin') || $user->hasRole('ops');
            }
            return in_array($user->role ?? null, ['admin','ops']);
        });

        // Legacy bearer token auth (DISABLED by default).
        // We have standardized on Laravel Sanctum for terminal authentication.
        // If absolutely necessary for a controlled migration window, you can enable
        // the legacy resolver by setting TSMS_ENABLE_LEGACY_JWT=true in the env.
        // Note: This registers a custom on-request resolver named 'terminal-legacy-jwt'
        // and intentionally does NOT override the default 'api' guard.
        if (env('TSMS_ENABLE_LEGACY_JWT', false)) {
            \Illuminate\Support\Facades\Log::warning('Legacy JWT guard (terminal-legacy-jwt) enabled via TSMS_ENABLE_LEGACY_JWT', [
                'reason' => 'temporary compatibility',
            ]);

            Auth::viaRequest('terminal-legacy-jwt', function ($request) {
                $token = $request->bearerToken();
                if (!$token) {
                    return null;
                }
                try {
                    // Simple lookup for legacy stored tokens. Prefer Sanctum going forward.
                    $terminal = PosTerminal::where('jwt_token', $token)->first();
                    // Require terminal to be active/valid to mitigate stale token risk
                    if ($terminal && method_exists($terminal, 'isActiveAndValid')) {
                        return $terminal->isActiveAndValid() ? $terminal : null;
                    }
                    return $terminal;
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Legacy JWT auth error', ['error' => $e->getMessage()]);
                    return null;
                }
            });
        }
    }
}