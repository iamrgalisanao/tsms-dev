<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseAuditLog extends Model
{
    protected $fillable = [
        'event_type',
        'reason_code',
        'severity',
        'license_id',
        'client_id',
        'deployment_id',
        'location_code',
        'tenant_id',
        'terminal_id',
        'module_code',
        'current_fingerprint_hash',
        'expected_fingerprint_hash',
        'request_method',
        'request_path',
        'ip_address',
        'user_agent',
        'user_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
