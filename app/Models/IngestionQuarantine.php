<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngestionQuarantine extends Model
{
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
    ];

    protected $casts = [
        'metadata' => 'array',
        'attempts' => 'integer',
    ];
}
