<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;


class Tenant extends Model
{
    protected $fillable = [
        'name',
        'code',
        'status',
    ];

    public function posTerminals()
    {
        return $this->hasMany(PosTerminal::class);
    }
}

