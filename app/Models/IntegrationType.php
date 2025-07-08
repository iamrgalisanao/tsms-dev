<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationType extends Model
{
    public $timestamps = false;
    protected $fillable = ['name'];

    public function terminals()
    {
        return $this->hasMany(PosTerminal::class, 'integration_type_id');
    }
}
