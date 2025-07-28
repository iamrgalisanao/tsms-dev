<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionJob extends Model
{
    use HasFactory;
    // Ensure job_status is always set on creation
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->job_status)) {
                throw new \InvalidArgumentException('job_status must be set when creating a TransactionJob.');
            }
        });
    }
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
