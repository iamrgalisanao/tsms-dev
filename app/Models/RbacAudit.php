<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RbacAudit extends Model
{
    protected $table = 'rbac_audits';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];
}
