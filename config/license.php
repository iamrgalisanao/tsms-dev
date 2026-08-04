<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TSMS License Redeployment Control
    |--------------------------------------------------------------------------
    |
    | Release 1 licensing is a redeployment-control perimeter. It protects the
    | current TSMS installation from being copied, restored, or reused outside
    | the approved client, deployment, and licensed location.
    |
    */

    'enabled' => (bool) env('LICENSE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Enforcement Mode
    |--------------------------------------------------------------------------
    |
    | disabled   No checks are applied.
    | observe    Checks run and violations are audited, but requests continue.
    | restricted Protected operations are blocked when license/deployment scope
    |            is invalid; diagnostics and recovery remain available.
    | enforce    Full blocking for invalid license, deployment, location,
    |            tenant, or terminal scope.
    |
    */
    'enforcement_mode' => env('LICENSE_ENFORCEMENT_MODE', 'observe'),

    'allowed_enforcement_modes' => [
        'disabled',
        'observe',
        'restricted',
        'enforce',
    ],

    /*
    |--------------------------------------------------------------------------
    | Deployment Scope
    |--------------------------------------------------------------------------
    |
    | These values must be confirmed per environment before restricted or
    | enforce mode is enabled.
    |
    */
    'client_id' => env('LICENSE_CLIENT_ID'),
    'deployment_id' => env('LICENSE_DEPLOYMENT_ID'),
    'license_id' => env('LICENSE_ID'),
    'location_code' => env('LICENSE_LOCATION_CODE'),
    'environment' => env('LICENSE_ENVIRONMENT', env('APP_ENV', 'production')),

    /*
    |--------------------------------------------------------------------------
    | License and Public Key Storage
    |--------------------------------------------------------------------------
    |
    | The signed license and public verification key must be stored outside the
    | public web root. The vendor private signing key must never be deployed to
    | the TSMS runtime environment.
    |
    */
    'paths' => [
        'license_file' => env(
            'LICENSE_FILE_PATH',
            storage_path('app/private/license.json')
        ),
        'public_key' => env(
            'LICENSE_PUBLIC_KEY_PATH',
            storage_path('app/private/license_public.key')
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vendor License Authority
    |--------------------------------------------------------------------------
    |
    | Client company admins are not license authorities. Reinstall, redeploy,
    | reuse, recovery, and replacement license actions require a vendor-owned
    | account with a vendor role and the matching license permission.
    |
    */
    'vendor_authority' => [
        'roles' => array_filter(array_map('trim', explode(',', env(
            'LICENSE_VENDOR_AUTHORITY_ROLES',
            'vendor,vendor_support,license_manager'
        )))),
        'manage_permission' => env('LICENSE_VENDOR_MANAGE_PERMISSION', 'license.manage'),
        'permissions' => [
            'view' => env('LICENSE_VENDOR_VIEW_PERMISSION', 'license.view'),
            'upload' => env('LICENSE_VENDOR_UPLOAD_PERMISSION', 'license.upload'),
            'recovery_request' => env('LICENSE_VENDOR_RECOVERY_PERMISSION', 'license.recovery.request'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fingerprint Policy
    |--------------------------------------------------------------------------
    |
    | Hard inputs are stable deployment identifiers. Soft inputs are diagnostic
    | signals only unless explicitly promoted by policy in a later release.
    |
    */
    'fingerprint' => [
        'hard_inputs' => [
            'deployment_id',
            'application_installation_uuid',
            'database_instance_uuid',
            'environment',
            'approved_server_identifier',
        ],
        'soft_inputs' => [
            'hostname',
            'private_ip',
            'public_ip',
            'mac_address',
            'cloud_instance_id',
        ],
        'approved_server_identifier' => env('LICENSE_APPROVED_SERVER_IDENTIFIER'),
        'hard_block_on_soft_input_change' => (bool) env('LICENSE_HARD_BLOCK_ON_SOFT_INPUT_CHANGE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnostics and Caching
    |--------------------------------------------------------------------------
    */
    'status_cache_ttl_seconds' => (int) env('LICENSE_STATUS_CACHE_TTL_SECONDS', 30),
    'audit_successes' => (bool) env('LICENSE_AUDIT_SUCCESSES', false),
];
