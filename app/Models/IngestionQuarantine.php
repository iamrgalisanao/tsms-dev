<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionQuarantine extends Model
{
    public const STATUS_NEW = 'NEW';
    public const STATUS_INSPECTED = 'INSPECTED';
    public const STATUS_REPLAY_READY = 'REPLAY_READY';
    public const STATUS_REPLAYED = 'REPLAYED';
    public const STATUS_FAILED = 'FAILED';

    protected $table = 'ingestion_quarantine';

    protected $fillable = [
        'submission_uuid',
        'tenant_id',
        'terminal_id',
        'payload',
        'payload_checksum_received',
        'payload_checksum_computed',
        'status',
        'metadata',
        'attempts',
        'replayed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'attempts' => 'integer',
        'replayed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }
}
