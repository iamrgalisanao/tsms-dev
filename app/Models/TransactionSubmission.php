<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * TransactionSubmission represents a logical submission envelope containing one or more transactions
 * identified by a submission_uuid originating from a single terminal.
 *
 * Idempotency rules:
 *  - (terminal_id, submission_uuid) must be unique
 *  - Replays with identical payload_checksum & transaction_count are treated as SUCCESS (idempotent)
 *  - Replays with differing payload_checksum or transaction_count are treated as CONFLICT
 */
class TransactionSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'terminal_id',
        'submission_uuid',
        'submission_timestamp',
        'transaction_count',
        'payload_checksum',
        'status',
    ];

    protected $casts = [
        'submission_timestamp' => 'datetime',
    ];

    // Status constants
    public const STATUS_RECEIVED   = 'RECEIVED';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED  = 'COMPLETED';
    public const STATUS_CONFLICT   = 'CONFLICT';

    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'submission_uuid', 'submission_uuid');
    }
}
