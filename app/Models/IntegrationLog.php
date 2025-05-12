<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationLog extends Model
{
    use HasFactory;
    
    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'response_metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];
    
    protected $fillable = [
        'tenant_id',
        'terminal_id',
        'transaction_id',
        'request_payload',
        'response_payload',
        'response_metadata',
        'status',
        'validation_status',
        'error_message',
        'http_status_code',
        'response_time',
        'retry_count',
        'retry_attempts',
        'next_retry_at',
        'retry_reason',
        'source_ip',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    protected $attributes = [
        'retry_count' => 0,
        'retry_attempts' => 0,
        'response_payload' => '{}',
        'response_metadata' => '{}',
    ];
}