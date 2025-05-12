<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityReport extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'security_report_template_id',
        'name',
        'status',
        'filters',
        'generated_by',
        'from_date',
        'to_date',
        'results',
        'export_path',
        'format',
        'error_message',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'filters' => 'array',
        'results' => 'array',
        'from_date' => 'datetime',
        'to_date' => 'datetime',
    ];

    /**
     * Get the tenant that owns the report.
     *
     * @return BelongsTo
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the template used to generate this report.
     *
     * @return BelongsTo
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(SecurityReportTemplate::class, 'security_report_template_id');
    }

    /**
     * Get the user who generated the report.
     *
     * @return BelongsTo
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
