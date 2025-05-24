<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    protected $fillable = [
        'type',
        'severity',
        'terminal_uid',
        'transaction_id',
        'message',
        'context'
    ];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime'
    ];

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_uid', 'terminal_uid');
    }

    public function getTypeClassAttribute()
    {
        return match($this->type) {
            'transaction' => 'info',
            'system' => 'primary',
            'auth' => 'warning',
            default => 'secondary'
        };
    }

    public function getSeverityClassAttribute()
    {
        return match($this->severity) {
            'error' => 'danger',
            'warning' => 'warning',
            'info' => 'info',
            default => 'secondary'
        };
    }
}