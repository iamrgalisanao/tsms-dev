<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'tenant_id',
        'terminal_id',
        'transaction_id',
        'request_payload',
        'response_payload',
        'status',
        'error_message',
        'http_status_code',
        'source_ip',
        'retry_count',
        'next_retry_at',
        'retry_reason',
        'validation_status',
        'response_time',
        'retry_attempts',
        'max_retries',
        'retry_success',
        'last_retry_at',
        // New columns
        'log_type',
        'user_id',
        'severity',
        'message',
        'context'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'next_retry_at' => 'datetime',
        'last_retry_at' => 'datetime',
        'context' => 'array',
        'retry_success' => 'boolean',
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];
    
    /**
     * Get valid status values
     * 
     * @return array
     */
    public static function getValidStatuses()
    {
        return ['SUCCESS', 'FAILED', 'PENDING', 'PROCESSING'];
    }
    
    /**
     * Boot method to register model events
     */
    protected static function boot()
    {
        parent::boot();
        
        // Add a saving event to ensure status is always valid
        static::saving(function ($model) {
            if (isset($model->status) && !in_array($model->status, self::getValidStatuses())) {
                $model->status = 'FAILED'; // Default to FAILED if invalid status
            }
        });
    }
    
    /**
     * Get the terminal that owns the integration log.
     */
    public function posTerminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }
    
    /**
     * Get the tenant that owns the integration log.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
    
    /**
     * Get the user that is associated with the integration log.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}