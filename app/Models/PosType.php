<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\PosTerminal;

class PosType extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        // Add other columns if present in your table, e.g. 'description'
    ];

    public function terminals()
    {
        return $this->hasMany(PosTerminal::class);
    }
}