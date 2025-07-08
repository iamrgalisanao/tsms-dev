<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TerminalStatus extends Model
{
    public $timestamps = false;
    protected $fillable = ['name'];

    public function terminals()
    {
        return $this->hasMany(PosTerminal::class, 'status_id');
    }
}