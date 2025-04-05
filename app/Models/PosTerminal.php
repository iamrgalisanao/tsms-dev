<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class PosTerminal extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'tenant_id',
        'terminal_uid',
        'registered_at',
        'status',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
