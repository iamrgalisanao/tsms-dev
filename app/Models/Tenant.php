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
     * Get the POS terminals for the tenant.
     */
    public function posTerminals()
    {
        return $this->hasMany(PosTerminal::class);
    }
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_code',
        'trade_name',
        'location_type',
        'location',
        'unit_no',
        'floor_area',
        'status',
        'category',
        'zone',
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