<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVendorLicenseAuthority
{
    public function handle(Request $request, Closure $next, ?string $permissionKey = null): Response
    {
        $user = $request->user();

        if (!$user || !$this->hasVendorRole($user) || !$this->hasRequiredPermission($user, $permissionKey)) {
            return new JsonResponse([
                'success' => false,
                'error_code' => 'LICENSE_VENDOR_AUTHORITY_REQUIRED',
                'message' => 'This license operation requires vendor authorization.',
            ], 403);
        }

        return $next($request);
    }

    private function hasVendorRole(object $user): bool
    {
        $roles = config('license.vendor_authority.roles', []);

        if ($roles === [] || !method_exists($user, 'hasAnyRole')) {
            return false;
        }

        return $user->hasAnyRole($roles);
    }

    private function hasRequiredPermission(object $user, ?string $permissionKey): bool
    {
        if (!method_exists($user, 'hasPermissionTo')) {
            return false;
        }

        $managePermission = config('license.vendor_authority.manage_permission', 'license.manage');
        if ($managePermission && $user->hasPermissionTo($managePermission)) {
            return true;
        }

        if ($permissionKey === null || $permissionKey === '') {
            return true;
        }

        $permission = config("license.vendor_authority.permissions.{$permissionKey}");

        return is_string($permission)
            && $permission !== ''
            && $user->hasPermissionTo($permission);
    }
}
