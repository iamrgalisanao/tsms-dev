<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends Model
{
    use HasApiTokens, HasFactory, SoftDeletes;

    /**
     * Get the users associated with the tenant.
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the POS terminals for the tenant.
     */
    public function posTerminals()
    {
        return $this->hasMany(PosTerminal::class);
    }

    protected $fillable = [
        'company_id',
        'customer_code',
        'trade_name',
        'location_type',
        'location',
        'location_code',
        'deployment_id',
        'license_id',
        'unit_no',
        'floor_area',
        'status',
        'accept_with_issues',
        'activity_monitoring_enabled',
        'activity_threshold_minutes',
        'activity_monitoring_notes',
        'activity_suppressed_until',
        'activity_suppression_reason',
        'activity_suppressed_by',
        'activity_suppressed_at',
        'category',
        'zone',
        'uuid',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $casts = [
        'accept_with_issues' => 'boolean',
        'activity_monitoring_enabled' => 'boolean',
        'activity_threshold_minutes' => 'integer',
        'activity_suppressed_until' => 'datetime',
        'activity_suppressed_at' => 'datetime',
    ];


    public function circuitBreakers()
    {
        return $this->hasMany(CircuitBreaker::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get daily sales total for a specific date (all terminals under this tenant).
     *
     * @param \Carbon\Carbon $date
     * @return float
     */
    public function getDailySalesTotal($date)
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        // Get all transactions from terminals in this tenant for the date
        $terminalIds = $this->posTerminals()->get()->pluck('id');
        return \App\Models\Transaction::whereIn('terminal_id', $terminalIds)
            ->whereBetween('transaction_timestamp', [$dayStart, $dayEnd])
            ->sum('gross_sales');
    }

    /**
     * Check if a new transaction would exceed daily limits for the tenant.
     *
     * @param float $amount
     * @param \Carbon\Carbon $date
     * @return bool
     */
    public function wouldExceedDailyLimit($amount, \Carbon\Carbon $date)
    {
        if (!$this->max_daily_sales) {
            return false;
        }
        $currentTotal = $this->getDailySalesTotal($date);
        return ($currentTotal + $amount) > $this->max_daily_sales;
    }
}
