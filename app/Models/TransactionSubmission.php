<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * TransactionSubmission represents a logical submission envelope containing one or more transactions
 * identified by a submission_uuid originating from a single terminal.
 *
 * Idempotency rules:
 *  - submission_uuid must be globally unique across terminals
 *  - Same-terminal replays with identical payload_checksum & transaction_count are treated as SUCCESS (idempotent)
 *  - Same-terminal replays with differing payload_checksum or transaction_count are treated as CONFLICT
 *  - Cross-terminal submission_uuid reuse is treated as CONFLICT
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
    public const STATUS_REJECTED   = 'REJECTED';

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
        return $this->hasMany(Transaction::class, 'terminal_id', 'terminal_id')
            ->where('submission_uuid', $this->submission_uuid);
    }
}
