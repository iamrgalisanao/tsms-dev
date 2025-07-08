<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'trade_name',
        'location_type',
        'location',
        'unit_no',
        'floor_area',
        'status',
        'category',
        'zone',
    ];

    public function posTerminals()
    {
        return $this->hasMany(PosTerminal::class);
    }

    public function circuitBreakers()
    {
        return $this->hasMany(CircuitBreaker::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}