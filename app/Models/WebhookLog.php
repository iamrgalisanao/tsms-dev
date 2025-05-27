<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    protected $fillable = [
        'terminal_id',
        'endpoint',
        'status',
        'request_payload',
        'response_payload',
        'error_message',
        'response_time'
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array'
    ];

    /**
     * Get the terminal associated with this webhook log.
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class);
    }
}
