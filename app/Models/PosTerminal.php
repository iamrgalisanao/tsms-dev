<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Tymon\JWTAuth\Contracts\JWTSubject;

class PosTerminal extends Model implements Authenticatable, JWTSubject
{
    use HasFactory, AuthenticatableTrait;

    protected $fillable = [
        'tenant_id',
        'provider_id',
        'terminal_uid',
        'machine_number',
        'pos_type',
        'supports_guest_count',
        'ip_whitelist',
        'device_fingerprint',
        'integration_type',
        'auth_type',
        'status',
        'is_sandbox',
        'webhook_url',
        'max_retries',
        'retry_interval_sec',
        'retry_enabled',
        'jwt_token',
        'registered_at',
        'enrolled_at',
        'created_at',
        'updated_at',
        'expires_at',
        'is_revoked'
    ];

    protected $casts = [
        'supports_guest_count' => 'boolean',
        'is_sandbox' => 'boolean',
        'retry_enabled' => 'boolean',
        'is_revoked' => 'boolean',
        'registered_at' => 'datetime',
        'enrolled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'expires_at' => 'datetime',
        'max_retries' => 'integer',
        'retry_interval_sec' => 'integer',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function provider()
    {
        return $this->belongsTo(PosProvider::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function integrationLogs()
    {
        return $this->hasMany(IntegrationLog::class, 'terminal_id');
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthPassword()
    {
        return $this->jwt_token;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'terminal_uid' => $this->terminal_uid,
            'tenant_id' => $this->tenant_id
        ];
    }
}