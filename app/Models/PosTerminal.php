<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable; // ⬅️ Use this instead of just Model
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class PosTerminal extends Authenticatable implements JWTSubject
{
    use Notifiable, HasFactory;

    protected $fillable = [
        'tenant_id',
        'provider_id',
        'terminal_uid',
        'registered_at',
        'enrolled_at',
        'status',
        'machine_number',
        'jwt_token', 
    ];
    
    protected $casts = [
        'registered_at' => 'datetime',
        'enrolled_at' => 'datetime',
    ];

    // JWT-required methods:
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * Get the tenant that owns the terminal
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    
    /**
     * Get the provider that owns the terminal
     */
    public function provider()
    {
        return $this->belongsTo(PosProvider::class);
    }
}