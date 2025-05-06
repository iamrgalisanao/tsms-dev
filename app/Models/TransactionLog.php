<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionLog extends Model
{
    protected $fillable = [
        'terminal_id',
        'transaction_type',
        'amount',
        'status',
        'request_payload',
        'response_payload',
        'processed_at'
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'processed_at' => 'datetime',
        'amount' => 'decimal:2'
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }
}