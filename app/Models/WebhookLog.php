<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'terminal_id',
        'event_type',
        'payload',
        'status',
        'received_at',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    /**
     * Get the terminal associated with this webhook log.
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class);
    }
}
