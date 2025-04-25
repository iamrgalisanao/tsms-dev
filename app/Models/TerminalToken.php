<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TerminalToken extends Model
{
    use HasFactory;

    protected $table = 'terminal_tokens';

    protected $fillable = [
        'terminal_id',
        'access_token',
        'issued_at',
        'expires_at',
        'revoked',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked' => 'boolean',
    ];

    /**
     * Relationship: Belongs to a POS terminal
     */
    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class);
    }

    /**
     * Scope to get only valid tokens (not revoked and not expired)
     */
    public function scopeValid($query)
    {
        return $query->where('revoked', false)
                     ->where(function ($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }
    // public function isValid()
    // {
    //     return $this->expires_at > now();
    // }

    public function isValid(): bool
    {
        return !$this->revoked
            && (!isset($this->expires_at) || now()->lt($this->expires_at))
            && (!isset($this->issued_at) || now()->gte($this->issued_at));
    }

    
}
