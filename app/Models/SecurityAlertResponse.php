<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityAlertResponse extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'security_alert_rule_id',
        'status',
        'acknowledged_by',
        'resolved_by',
        'acknowledged_at',
        'resolved_at',
        'response_notes',
        'resolution_notes',
        'context',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'context' => 'array',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns the alert response.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the alert rule associated with this response.
     *
     * @return BelongsTo
     */
    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(SecurityAlertRule::class, 'security_alert_rule_id');
    }

    /**
     * Get the user who acknowledged the alert.
     *
     * @return BelongsTo
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Get the user who resolved the alert.
     *
     * @return BelongsTo
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
