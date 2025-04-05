<?php

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Model;

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

