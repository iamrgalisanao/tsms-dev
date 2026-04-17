<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionIntake extends Model
{
    protected $table = 'transaction_intake';

    protected $fillable = [
        'submission_uuid',
        'tenant_id',
        'terminal_id',
        'payload_checksum',
        'payload',
        'payload_size_bytes',
        'source_ip',
        'intake_status',
        'processing_status',
        'attempt_count',
        'last_error_code',
        'last_error_message',
        'duplicate_of_intake_id',
        'trace_id',
        'received_at',
        'queued_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
        'queued_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempt_count' => 'integer',
        'payload_size_bytes' => 'integer',
    ];

    // Status Constants
    public const INTAKE_STATUS_RECEIVED = 'RECEIVED';
    public const INTAKE_STATUS_REJECTED = 'REJECTED';
    public const INTAKE_STATUS_ACCEPTED = 'ACCEPTED';
    public const INTAKE_STATUS_QUEUED = 'QUEUED';

    public const PROCESSING_STATUS_PROCESSING = 'PROCESSING';
    public const PROCESSING_STATUS_PROCESSED = 'PROCESSED';
    public const PROCESSING_STATUS_DUPLICATE = 'DUPLICATE';
    public const PROCESSING_STATUS_FAILED_RETRYABLE = 'FAILED_RETRYABLE';
    public const PROCESSING_STATUS_FAILED_PERMANENT = 'FAILED_PERMANENT';
    public const PROCESSING_STATUS_DEAD_LETTERED = 'DEAD_LETTERED';

    /**
     * Get the tenant that owns the intake.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the terminal that owns the intake.
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    /**
     * Get the original intake if this is a duplicate.
     */
    public function originalIntake(): BelongsTo
    {
        return $this->belongsTo(TransactionIntake::class, 'duplicate_of_intake_id');
    }
}
