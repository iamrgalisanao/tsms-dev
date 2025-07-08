<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_code',
        'company_name',
        'tin',
    ];

    public function tenants()
    {
        return $this->hasMany(Tenant::class);
    }
}