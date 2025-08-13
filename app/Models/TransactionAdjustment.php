<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionAdjustment extends Model
{
    use HasFactory;
    protected $table = 'transaction_adjustments';
    protected $fillable = [
        'transaction_pk',
        'adjustment_type',
        'amount',
    ];
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_pk', 'id');
    }
}
