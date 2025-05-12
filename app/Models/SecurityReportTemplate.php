<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecurityReportTemplate extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'filters',
        'type',
        'columns',
        'format',
        'is_scheduled',
        'schedule_frequency',
        'notification_settings',
        'is_system',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'filters' => 'array',
        'columns' => 'array',
        'notification_settings' => 'array',
        'is_scheduled' => 'boolean',
        'is_system' => 'boolean',
    ];

    /**
     * Get the tenant that owns the report template.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the reports generated from this template.
     *
     * @return HasMany
     */
    public function reports(): HasMany
    {
        return $this->hasMany(SecurityReport::class);
    }
}
