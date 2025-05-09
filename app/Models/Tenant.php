<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'status',
    ];

    public function posTerminals()
    {
        return $this->hasMany(PosTerminal::class);
    }

    public function circuitBreakers()
    {
        return $this->hasMany(CircuitBreaker::class);
    }
}