<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Early binding for services that other facades might depend on
        $this->app->singleton('transaction.validation', function ($app) {
            return new \App\Services\TransactionValidationService();
        });

        $this->app->bind(
            \App\Services\Backfill\ConnectionIdentityResolver::class,
            \App\Services\Backfill\DatabaseConnectionIdentityResolver::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Set default string length for schema
        Schema::defaultStringLength(191);

        $this->configureRateLimiting();
        
        // Ensure app is fully initialized before bootstrapping services
        if ($this->app->runningInConsole()) {
            return;
        }

        $this->removeStaleViteHotFileOutsideLocalhost();

        // Make sure View facade is available
        if (!$this->app->bound('view')) {
            $this->app->singleton('view', function ($app) {
                return $app->make(\Illuminate\View\Factory::class);
            });
        }

        // Make StatusHelper available to all views
        View::share('StatusHelper', \App\Helpers\StatusHelper::class);
        View::share('BadgeHelper', \App\Helpers\BadgeHelper::class);
        View::share('LogHelper', \App\Helpers\LogHelper::class);

        // Prod-only: warn if legacy auth flags accidentally enabled
        try {
            if (app()->environment('production')) {
                if (env('TSMS_ENABLE_LEGACY_JWT', false) || env('TSMS_ENABLE_LEGACY_API', false)) {
                    \Log::warning('Legacy auth flags enabled in production', [
                        'TSMS_ENABLE_LEGACY_JWT' => (bool) env('TSMS_ENABLE_LEGACY_JWT', false),
                        'TSMS_ENABLE_LEGACY_API' => (bool) env('TSMS_ENABLE_LEGACY_API', false),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // no-op
        }
    }

    private function removeStaleViteHotFileOutsideLocalhost(): void
    {
        try {
            $host = request()->getHost();
            $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);

            if (app()->environment('local') && $isLocalhost) {
                return;
            }

            $hotFile = public_path('hot');
            if (is_file($hotFile) && ! @unlink($hotFile)) {
                Log::warning('Unable to remove stale Vite hot file outside localhost.', [
                    'host' => $host,
                    'hot_file' => $hotFile,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Unable to check stale Vite hot file.', ['error' => $e->getMessage()]);
        }
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('pos-ingestion', function (Request $request) {
            $limit = config('rate-limiting.default_limits.pos_ingestion');

            return Limit::perMinute((int) $limit['attempts'])
                ->by($this->posRateLimitKey($request, 'ingestion'))
                ->response($this->rateLimitResponse('pos-ingestion'));
        });

        RateLimiter::for('pos-read', function (Request $request) {
            $limit = config('rate-limiting.default_limits.pos_read');

            return Limit::perMinute((int) $limit['attempts'])
                ->by($this->posRateLimitKey($request, 'read'))
                ->response($this->rateLimitResponse('pos-read'));
        });

        RateLimiter::for('pos-auth', function (Request $request) {
            $limit = config('rate-limiting.default_limits.pos_auth');
            $hardwareId = (string) $request->input('hardware_id', $request->input('serial_number', 'unknown'));

            return Limit::perMinute((int) $limit['attempts'])
                ->by('pos:auth:' . sha1($request->ip() . '|' . $hardwareId))
                ->response($this->rateLimitResponse('pos-auth'));
        });

        RateLimiter::for('pos-heartbeat', function (Request $request) {
            $limit = config('rate-limiting.default_limits.pos_heartbeat');

            return Limit::perMinute((int) $limit['attempts'])
                ->by($this->posRateLimitKey($request, 'heartbeat'))
                ->response($this->rateLimitResponse('pos-heartbeat'));
        });

        RateLimiter::for('webapp', function (Request $request) {
            $limit = config('rate-limiting.default_limits.webapp');
            $user = $request->user();
            $identifier = $user ? 'user:' . $user->getAuthIdentifier() : 'ip:' . $request->ip();

            return Limit::perMinute((int) $limit['attempts'])
                ->by('webapp:' . $identifier)
                ->response($this->rateLimitResponse('webapp'));
        });
    }

    private function posRateLimitKey(Request $request, string $scope): string
    {
        $terminal = $request->user();
        $tenantId = $terminal?->tenant_id ?? $request->input('tenant_id', 'unknown');
        $terminalId = $terminal?->getAuthIdentifier() ?? $request->input('terminal_id', $request->ip());

        return sprintf('pos:%s:tenant:%s:terminal:%s', $scope, $tenantId, $terminalId);
    }

    private function rateLimitResponse(string $scope): \Closure
    {
        return function (Request $request, array $headers) use ($scope) {
            $retryAfter = (int) ($headers['Retry-After'] ?? 60);
            $user = $request->user();

            Log::warning('Rate limit exceeded', [
                'scope' => $scope,
                'ip' => $request->ip(),
                'path' => $request->path(),
                'tenant_id' => $user?->tenant_id ?? $request->input('tenant_id'),
                'terminal_id' => $user?->getAuthIdentifier() ?? $request->input('terminal_id'),
                'retry_after' => $retryAfter,
                'correlation_id' => $request->headers->get('X-Correlation-ID'),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Too many requests.',
                'retry_after' => $retryAfter,
            ], 429, $headers);
        };
    }
}
