<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IntegrationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'terminal_id',
        'tenant_id',
        'status',
        'retry_count',
        'retry_reason',
        'response_time',
        'retry_success',
        'last_retry_at',
    ];

    protected $casts = [
        'retry_success' => 'boolean',
        'last_retry_at' => 'datetime',
    ];

    /**
     * Get the POS terminal that owns the integration log.
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
}