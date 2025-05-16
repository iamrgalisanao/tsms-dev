<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TerminalToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'terminal_id',
        'access_token',
        'issued_at',
        'expires_at',
        'is_revoked',
        'revoked_at',
        'revoked_reason',
        'last_used_at'
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'is_revoked' => 'boolean',
    ];

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function isValid()
    {
        return !$this->is_revoked && 
               $this->expires_at->isFuture();
    }
}