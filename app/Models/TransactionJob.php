<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionJob extends Model
{
    use HasFactory;
    protected $table = 'transaction_jobs';
    protected $fillable = [
        'transaction_id',
        'job_status',
        'last_error',
        'attempts',
        'retry_count',
        'completed_at',
    ];
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }
}
