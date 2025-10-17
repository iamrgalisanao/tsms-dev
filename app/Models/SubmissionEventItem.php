<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubmissionEventItem extends Model
{
    use HasFactory;

    protected $table = 'submission_event_items';

    protected $fillable = [
        'submission_uuid',
        'tenant_id',
        'terminal_id',
        'transaction_id',
        'status',
        'reason_code',
        'reason_details',
        'occurred_at',
        'correlation_id',
    ];

    protected $casts = [
        'reason_details' => 'array',
        'occurred_at' => 'datetime',
    ];
}
