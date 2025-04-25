<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class TerminalToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'terminal_id',
        'token',
        'issued_at',
        'expires_at',
        'last_used_at',
        'is_revoked',
        'revoked_at',
        'revoked_reason'
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'is_revoked' => 'boolean'
    ];

    /**
     * Get the terminal that owns this token
     */
    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    /**
     * Check if the token is valid (not expired and not revoked)
     * 
     * @return bool
     */
    public function isValid(): bool
    {
        // Check for revocation first (most critical)
        if ($this->is_revoked) {
            \Log::info("Token {$this->id} rejected: revoked at {$this->revoked_at}");
            return false;
        }

        // Check for expiration
        if ($this->expires_at->isPast()) {
            \Log::info("Token {$this->id} rejected: expired at {$this->expires_at}");
            return false;
        }

        // Check for terminal status (additional security)
        $terminal = $this->terminal;
        if (!$terminal) {
            \Log::warning("Token {$this->id} rejected: terminal not found");
            return false;
        }

        if (!$terminal->is_active) {
            \Log::warning("Token {$this->id} rejected: terminal {$terminal->id} is inactive");
            return false;
        }

        // Update last used timestamp
        $this->last_used_at = Carbon::now();
        $this->save();

        return true;
    }

    /**
     * Revoke this token
     * 
     * @param string $reason The reason for revocation
     * @return bool
     */
    public function revoke(string $reason = 'manually_revoked'): bool
    {
        $this->is_revoked = true;
        $this->revoked_at = Carbon::now();
        $this->revoked_reason = $reason;
        return $this->save();
    }

    /**
     * Update token expiration
     * 
     * @param int $days Number of days from now
     * @return bool
     */
    public function extendExpiration(int $days = 30): bool
    {
        $this->expires_at = Carbon::now()->addDays($days);
        return $this->save();
    }
}
