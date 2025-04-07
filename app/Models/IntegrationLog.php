<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationLog extends Model
{
    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'response_metadata' => 'array', // ✅ THIS IS REQUIRED
    ];
    
    protected $fillable = [
        'tenant_id',
        'terminal_id',
        'transaction_id',
        'request_payload',
        'response_payload',
        'response_metadata', // ✅ This should be included
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
    
}
