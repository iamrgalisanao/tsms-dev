<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecurityAlertRule extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'event_type',
        'threshold',
        'window_minutes',
        'action',
        'notification_channels',
        'is_active'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'threshold' => 'integer',
        'window_minutes' => 'integer',
        'notification_channels' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get the tenant that owns the security alert rule.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get all events that match this rule's event type.
     */
    public function matchingEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class, 'event_type', 'event_type')
                    ->where('tenant_id', $this->tenant_id);
    }

    /**
     * Check if this rule has been triggered based on its threshold within the window.
     *
     * @return bool
     */
    public function isTriggered(): bool
    {
        $windowStart = now()->subMinutes($this->window_minutes);
        
        $eventCount = $this->matchingEvents()
            ->where('created_at', '>=', $windowStart)
            ->count();

        return $eventCount >= $this->threshold;
    }
}
