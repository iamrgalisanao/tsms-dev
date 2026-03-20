<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionIdentity extends Model
{
    use \App\Traits\BelongsToTenant;
    protected $table = 'transaction_identities';

    protected $fillable = [
        'tenant_id',
        'terminal_id',
        'canonical_fingerprint',
        'first_transaction_id'
    ];

    // keep timestamps
}
