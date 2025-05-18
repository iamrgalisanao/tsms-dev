<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;

class PosTerminal extends Model implements Authenticatable
{
    use HasFactory, AuthenticatableTrait;

    protected $fillable = [
        'tenant_id',
        'provider_id',
        'terminal_uid',
        'registered_at',
        'enrolled_at',
        'status',
        'is_sandbox',
        'webhook_url',
        'max_retries',
        'retry_interval_sec',
        'retry_enabled',
        'jwt_token',
        'expires_at',
        'is_revoked'
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'enrolled_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_sandbox' => 'boolean',
        'retry_enabled' => 'boolean',
        'is_revoked' => 'boolean'
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
    
    public function getAuthIdentifierName()
    {
        return 'id';
    }
    
    public function getAuthPassword()
    {
        return $this->jwt_token;
    }
}