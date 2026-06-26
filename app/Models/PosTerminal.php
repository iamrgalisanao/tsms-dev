<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Foundation\Auth\Access\Authorizable as AuthorizableTrait;
use Laravel\Sanctum\HasApiTokens;

class PosTerminal extends Model implements Authenticatable, Authorizable
{
    use HasFactory, AuthenticatableTrait, AuthorizableTrait, HasApiTokens, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'provider_id',
        'serial_number',
        'machine_number',
        'supports_guest_count',
        'pos_type_id',
        'integration_type_id',
        'auth_type_id',
        'status_id',
    'ip_address',
        'registered_at',
        'last_seen_at',
	'last_sale_at',
    'last_ip_at',
        'heartbeat_threshold',
        'activity_monitoring_enabled',
        'activity_threshold_minutes',
        'activity_monitoring_notes',
        'activity_suppressed_until',
        'activity_suppression_reason',
        'activity_suppressed_by',
        'activity_suppressed_at',
        'expires_at',
        'callback_url',
        'notification_preferences',
        'notifications_enabled',
        'api_key',           // For initial authentication
        'is_active',         // For activation status
    ];

    protected $casts = [
        'supports_guest_count' => 'boolean',
        'notifications_enabled' => 'boolean',
        'notification_preferences' => 'array',
        'is_active' => 'boolean',
        'activity_monitoring_enabled' => 'boolean',
        'activity_threshold_minutes' => 'integer',
        'activity_suppressed_until' => 'datetime',
        'activity_suppressed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'expires_at' => 'datetime',
        'registered_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_sale_at' => 'datetime',
        'last_ip_at' => 'datetime',
    ];

    // Use serial_number as the auth identifier
    public function getAuthIdentifierName()
    {
        return 'serial_number';
    }

    public function getAuthPassword()
    {
        return $this->api_key;
    }

    // Sanctum token abilities
    public function getTokenAbilities()
    {
        return [
            'transaction:create',
            'transaction:read',
            'transaction:status',
            'heartbeat:send',
        ];
    }

    // Generate access token using serial number
    public function generateAccessToken()
    {
        $this->tokens()->delete();
        
        $token = $this->createToken(
            'terminal-' . $this->serial_number,
            $this->getTokenAbilities()
        );

        return $token->plainTextToken;
    }

    // Check if terminal is active and valid
    public function isActiveAndValid()
    {
        return $this->is_active && 
               $this->status_id === 1 && // Assuming 1 = active status
               (!$this->expires_at || $this->expires_at->isFuture());
    }

    // Relational definitions moved to BelongsToTenant trait

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
}
