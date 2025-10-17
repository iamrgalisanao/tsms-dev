<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubmissionEvent extends Model
{
    use HasFactory;

    protected $table = 'submission_events';

    protected $fillable = [
        'submission_uuid',
        'tenant_id',
        'terminal_id',
        'status',
        'reason_code',
        'reason_details',
        'transaction_count',
        'occurred_at',
    ];

    protected $casts = [
        'reason_details' => 'array',
        'occurred_at' => 'datetime',
    ];
}
