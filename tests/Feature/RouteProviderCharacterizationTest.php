<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

class RouteProviderCharacterizationTest extends TestCase
{
    public function test_license_routes_keep_vendor_auth_without_license_enforcement(): void
    {
        $routes = $this->routesForPrefix('api/license');

        $this->assertSame([
            'GET|HEAD api/license/capabilities',
            'GET|HEAD api/license/status',
            'POST api/license/recovery-request',
            'POST api/license/upload',
        ], $this->routeSignatures($routes));

        foreach ($routes as $route) {
            $middleware = $this->expandedMiddleware($route);

            $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class . ':sanctum', $middleware);
            $this->assertContains(\App\Http\Middleware\EnsureVendorLicenseAuthority::class . ':' . $this->licensePermissionKey($route), $middleware);
            $this->assertNotContains(\App\Http\Middleware\LicenseMiddleware::class, $middleware);
        }
    }

    public function test_pos_batch_route_keeps_terminal_auth_license_and_intake_guards(): void
    {
        $routes = $this->routesForUri('api/v1/transactions/batch');

        $this->assertCount(1, $routes);

        $route = $routes->first();
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('App\Http\Controllers\API\V1\TransactionController@batchStore', $route->getActionName());

        $middleware = $this->expandedMiddleware($route);

        $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class . ':sanctum', $middleware);
        $this->assertContains(\App\Http\Middleware\CaptureTerminalIp::class, $middleware);
        $this->assertContains(\App\Http\Middleware\AttachCorrelationId::class, $middleware);
        $this->assertContains(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class . ':transaction:create', $middleware);
        $this->assertContains(\Illuminate\Routing\Middleware\ThrottleRequests::class . ':pos-ingestion', $middleware);
        $this->assertContains(\App\Http\Middleware\LicenseMiddleware::class, $middleware);
        $this->assertContains(\App\Http\Middleware\IngestionBackpressureMiddleware::class . ':processing', $middleware);
        $this->assertContains(\App\Http\Middleware\CircuitBreakerMiddleware::class . ':transaction-intake', $middleware);
    }

    public function test_dashboard_license_and_pos_route_duplicate_counts_stay_zero(): void
    {
        $routes = $this->characterizedRoutes();

        $this->assertSame([], $this->duplicateCounts($routes, fn (Route $route) => implode('|', $route->methods()) . ' ' . $route->uri()));
        $this->assertSame([], $this->duplicateCounts($routes, fn (Route $route) => $route->getName()));
        $this->assertSame([], $this->duplicateCounts($routes, fn (Route $route) => $route->getActionName()));
    }

    private function routesForPrefix(string $prefix)
    {
        return collect(app('router')->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), $prefix))
            ->values();
    }

    private function routesForUri(string $uri)
    {
        return collect(app('router')->getRoutes())
            ->filter(fn (Route $route) => $route->uri() === $uri)
            ->values();
    }

    private function characterizedRoutes()
    {
        $posUris = [
            'api/v1/auth/terminal',
            'api/v1/auth/refresh',
            'api/v1/auth/me',
            'api/v1/heartbeat',
            'api/v1/transactions/batch',
            'api/v1/transactions/official',
            'api/v1/transactions/{transaction_id}/refund',
            'api/v1/transactions/{transaction_id}/void',
            'api/v1/transactions/{transaction}/status',
            'api/v1/submissions/{submission_uuid}',
            'api/v1/sandbox/payload/validate',
            'api/v1/checksum/sandbox',
        ];

        return collect(app('router')->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/dashboard')
                || str_starts_with($route->uri(), 'api/license')
                || in_array($route->uri(), $posUris, true))
            ->values();
    }

    private function routeSignatures($routes): array
    {
        return $routes
            ->map(fn (Route $route) => implode('|', $route->methods()) . ' ' . $route->uri())
            ->sort()
            ->values()
            ->all();
    }

    private function expandedMiddleware(Route $route): array
    {
        return app('router')->gatherRouteMiddleware($route);
    }

    private function duplicateCounts($routes, callable $keyForRoute): array
    {
        return $routes
            ->map($keyForRoute)
            ->filter()
            ->countBy()
            ->filter(fn (int $count) => $count > 1)
            ->all();
    }

    private function licensePermissionKey(Route $route): string
    {
        return match ($route->uri()) {
            'api/license/upload' => 'upload',
            'api/license/recovery-request' => 'recovery_request',
            default => 'view',
        };
    }
}
