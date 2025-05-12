<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\SecurityAlertResponse;

class SecurityAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'severity',
        'status',
        'source',
        'context',
        'tenant_id',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
        'resolved_by',
        'resolution_status',
    ];

    protected $casts = [
        'context' => 'json',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Get the responses (notes and actions) for this alert
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SecurityAlertResponse::class, 'alert_id');
    }
}
