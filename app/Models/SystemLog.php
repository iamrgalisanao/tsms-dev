<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'log_type',
        'severity',
        'transaction_id',
        'terminal_uid',
        'message',
        'context'
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
        return $this->belongsTo(PosTerminal::class, 'terminal_uid', 'terminal_uid');
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