<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionTax extends Model
{
    use HasFactory;
    protected $table = 'transaction_taxes';
    protected $fillable = [
        'transaction_pk',
        'tax_type',
        'amount',
    ];
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_pk', 'id');
    }
}
