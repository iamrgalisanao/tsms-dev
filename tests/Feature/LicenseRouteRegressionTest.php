<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Licensing\DeploymentFingerprintService;
use App\Services\Licensing\LicenseReasonCode;
use App\Services\Licensing\LicenseService;
use App\Services\Licensing\LicenseValidationResult;
use App\Services\Licensing\SignedLicenseReader;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LicenseRouteRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'license.enabled' => true,
            'license.enforcement_mode' => 'enforce',
            'license.client_id' => 'MWM',
            'license.deployment_id' => 'MWM-PITX-MANILA-PROD-001',
            'license.license_id' => 'LIC-MWM-PITX-001',
            'license.location_code' => 'PITX_MANILA',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app->instance(LicenseService::class, new InvalidRouteRegressionLicenseService());

        Sanctum::actingAs($this->vendorUser(), ['*']);
    }

    public function test_license_routes_do_not_include_license_valid_middleware(): void
    {
        $router = app('router');
        $licenseRoutes = collect($router->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/license'));

        $this->assertCount(4, $licenseRoutes);

        foreach ($licenseRoutes as $route) {
            $middleware = $router->gatherRouteMiddleware($route);

            $this->assertNotContains('license.valid', $route->gatherMiddleware(), $route->uri());
            $this->assertNotContains(\App\Http\Middleware\LicenseMiddleware::class, $middleware, $route->uri());
        }
    }

    public function test_license_read_routes_remain_reachable_when_license_is_invalid_under_enforce_mode(): void
    {
        $this->getJson('/api/license/status')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.license_valid', false)
            ->assertJsonPath('data.reason_code', LicenseReasonCode::LicenseExpired->value)
            ->assertJsonPath('data.enforcement_mode', 'enforce');

        $this->getJson('/api/license/capabilities')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.license_valid', false)
            ->assertJsonPath('data.reason_code', LicenseReasonCode::LicenseExpired->value)
            ->assertJsonPath('data.enforcement_mode', 'enforce');
    }

    public function test_license_write_routes_reach_their_controller_when_license_is_invalid_under_enforce_mode(): void
    {
        $this->postJson('/api/license/recovery-request', [
            'reason' => 'Emergency recovery validation from regression test.',
            'requested_duration_days' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.license_valid', false)
            ->assertJsonPath('data.license_reason_code', LicenseReasonCode::LicenseExpired->value);

        $this->post('/api/license/upload', [
            'license_file' => UploadedFile::fake()->create('candidate.txt', 1, 'text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', LicenseReasonCode::LicenseSchemaInvalid->value);
    }

    private function vendorUser(): User
    {
        $role = Role::findOrCreate('license_manager', 'web');
        $permission = Permission::findOrCreate('license.manage', 'web');
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}

class InvalidRouteRegressionLicenseService extends LicenseService
{
    public function __construct()
    {
        parent::__construct(new SignedLicenseReader(), new DeploymentFingerprintService());
    }

    public function validateLicense(bool $refresh = false): LicenseValidationResult
    {
        return LicenseValidationResult::invalid(LicenseReasonCode::LicenseExpired);
    }

    public function getLicenseStatus(): array
    {
        return $this->validateLicense()->safeStatus();
    }
}
