<?php


namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable; // ⬅️ Use this instead of just Model
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class PosTerminal extends Authenticatable implements JWTSubject
{
    use Notifiable;

    protected $fillable = [
        'tenant_id',
        'terminal_uid',
        'registered_at',
        'status',
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
}

