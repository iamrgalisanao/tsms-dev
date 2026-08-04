<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeploymentMetadata extends Model
{
    use HasFactory;

    protected $table = 'deployment_metadata';

    protected $fillable = [
        'deployment_id',
        'license_id',
        'client_id',
        'environment',
        'application_installation_uuid',
        'database_instance_uuid',
        'current_fingerprint_hash',
        'first_activated_at',
        'last_validated_at',
        'status',
    ];

    protected $casts = [
        'first_activated_at' => 'datetime',
        'last_validated_at' => 'datetime',
    ];
}
