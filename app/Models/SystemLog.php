<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SystemLog extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'log_type',
        'severity',
        'transaction_id',
        'terminal_uid',
        'message',
        'context',
        'user_id'  // Add this to fillable
    ];

    protected $casts = [
        'context' => 'array'
    ];

    // Relationships
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function terminal()
    {
        // Local key (SystemLog.terminal_uid) maps to PosTerminal.serial_number per normalized schema
        return $this->belongsTo(PosTerminal::class, 'terminal_uid', 'serial_number');
    }

    // Backward-compatible alias used by some legacy views
    public function posTerminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_uid', 'serial_number');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeError($query)
    {
        return $query->where('severity', 'error');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }
}