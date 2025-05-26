<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetryHistory extends Model
{
    protected $fillable = [
        'transaction_id',
        'attempt_number',
        'status',
        'initiated_at',
        'error_message'
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
