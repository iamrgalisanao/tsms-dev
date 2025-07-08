<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthType extends Model
{
    public $timestamps = false;
    protected $fillable = ['name'];

    public function terminals()
    {
        return $this->hasMany(PosTerminal::class, 'auth_type_id');
    }
}
