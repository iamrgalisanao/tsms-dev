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
        'serial_number',
        'machine_number',
        'supports_guest_count',
        'pos_type_id',
        'integration_type_id',
        'auth_type_id',
        'status_id',
        'registered_at',
        'last_seen_at',
        'heartbeat_threshold',
        'expires_at',
        'callback_url',
        'notification_preferences',
        'notifications_enabled',
    ];

    protected $casts = [
        'supports_guest_count' => 'boolean',
        'notifications_enabled' => 'boolean',
        'notification_preferences' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'expires_at' => 'datetime',
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

    public function posType()
    {
        return $this->belongsTo(PosType::class, 'pos_type_id');
    }

    public function integrationType()
    {
        return $this->belongsTo(IntegrationType::class, 'integration_type_id');
    }

    public function authType()
    {
        return $this->belongsTo(AuthType::class, 'auth_type_id');
    }

    public function status()
    {
        return $this->belongsTo(TerminalStatus::class, 'status_id');
    }

    public function config()
    {
        return $this->hasOne(TerminalConfig::class, 'terminal_id');
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