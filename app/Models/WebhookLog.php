<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    protected $fillable = [
        'terminal_id',
        'transaction_id',
        'status',
        'http_code',
        'response_body',
        'error_message',
        'retry_count',
        'max_retries',
        'sent_at',
        'last_attempt_at',
        'next_retry_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    /**
     * Get the terminal associated with this webhook log.
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }
} 
