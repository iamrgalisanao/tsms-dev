<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionHistory extends Model
{
    protected $fillable = [
        'transaction_id',
        'status',
        'message',
        'attempt_number',
        'created_by'
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function getStatusColorAttribute()
    {
        return match($this->status) {
            'COMPLETED' => 'success',
            'FAILED' => 'danger',
            'PROCESSING' => 'info',
            default => 'secondary'
        };
    }
}