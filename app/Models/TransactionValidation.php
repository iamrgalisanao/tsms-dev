<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionValidation extends Model
{
    use HasFactory;
    protected $table = 'transaction_validations';
    protected $fillable = [
        'transaction_pk',
        'status_code',
        'validation_details',
        'error_code',
        'validated_at',
    ];
    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'transaction_pk', 'id');
    }
}
