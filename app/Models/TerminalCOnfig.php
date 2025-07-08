<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerminalConfig extends Model
{
    protected $primaryKey = 'terminal_id';
    public $incrementing = false;

    protected $fillable = [
        'terminal_id',
        'webhook_url',
        'max_retries',
        'retry_interval_sec',
        'retry_enabled',
        'ip_whitelist',
        'device_fingerprint',
        'is_sandbox',
    ];

    protected $casts = [
        'max_retries' => 'integer',
        'retry_interval_sec' => 'integer',
        'retry_enabled' => 'boolean',
        'is_sandbox' => 'boolean',
    ];

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }
}