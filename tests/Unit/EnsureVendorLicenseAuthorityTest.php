<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureVendorLicenseAuthority;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class EnsureVendorLicenseAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'license' => [
                'vendor_authority' => [
                    'roles' => ['vendor', 'vendor_support', 'license_manager'],
                    'manage_permission' => 'license.manage',
                    'permissions' => [
                        'view' => 'license.view',
                        'upload' => 'license.upload',
                        'recovery_request' => 'license.recovery.request',
                    ],
                ],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_client_admin_role_without_vendor_role_is_denied(): void
    {
        $request = $this->requestFor(new FakeLicenseAuthorityUser(['admin'], ['license.manage']));

        $response = (new EnsureVendorLicenseAuthority())->handle(
            $request,
            fn () => new Response('ok', 200),
            'upload'
        );

        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('LICENSE_VENDOR_AUTHORITY_REQUIRED', $payload['error_code']);
    }

    public function test_vendor_role_with_endpoint_permission_is_allowed(): void
    {
        $request = $this->requestFor(new FakeLicenseAuthorityUser(['vendor_support'], ['license.upload']));

        $response = (new EnsureVendorLicenseAuthority())->handle(
            $request,
            fn () => new Response('ok', 200),
            'upload'
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_vendor_role_with_manage_permission_is_allowed_for_any_license_operation(): void
    {
        $request = $this->requestFor(new FakeLicenseAuthorityUser(['license_manager'], ['license.manage']));

        $response = (new EnsureVendorLicenseAuthority())->handle(
            $request,
            fn () => new Response('ok', 200),
            'recovery_request'
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    private function requestFor(object $user): Request
    {
        $request = Request::create('/api/license/upload', 'POST');
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}

class FakeLicenseAuthorityUser
{
    public function __construct(
        private readonly array $roles,
        private readonly array $permissions,
    ) {
    }

    public function hasAnyRole(array $roles): bool
    {
        return array_intersect($this->roles, $roles) !== [];
    }

    public function hasPermissionTo(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
