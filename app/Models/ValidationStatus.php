<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValidationStatus extends Model
{
    protected $table = 'validation_statuses';
    public $incrementing = false;
    public $timestamps = false;
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    protected $fillable = ['code', 'description'];
}
