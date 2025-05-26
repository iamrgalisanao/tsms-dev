<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Store extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'address',
        'city',
        'state',
        'postal_code',
        'phone',
        'email',
        'operating_hours',
        'status',
        'allows_service_charge',
        'tax_exempt',
        'max_daily_sales',
        'max_transaction_amount'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'operating_hours' => 'array',
        'allows_service_charge' => 'boolean',
        'tax_exempt' => 'boolean',
        'max_daily_sales' => 'decimal:2',
        'max_transaction_amount' => 'decimal:2',
    ];

    /**
     * Get the tenant that owns the store.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the terminals for the store.
     */
    public function terminals()
    {
        return $this->hasMany(PosTerminal::class);
    }

    /**
     * Check if store is open at a specific time
     * 
     * @param \Carbon\Carbon $dateTime
     * @return bool
     */
    public function isOpenAt($dateTime)
    {
        $dayOfWeek = strtolower($dateTime->format('l'));
        
        // If no operating hours are set for this day, consider closed
        if (!isset($this->operating_hours[$dayOfWeek])) {
            return false;
        }
        
        $hours = $this->operating_hours[$dayOfWeek];
        $openTime = $dateTime->copy()->setTimeFromTimeString($hours['open']);
        $closeTime = $dateTime->copy()->setTimeFromTimeString($hours['close']);
        
        return $dateTime->between($openTime, $closeTime);
    }

    /**
     * Get daily sales total for a specific date
     * 
     * @param \Carbon\Carbon $date
     * @return float
     */
    public function getDailySalesTotal($date)
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();
        
        // Get all transactions from terminals in this store for the date
        $total = Transaction::whereIn('terminal_id', $this->terminals->pluck('id'))
            ->whereBetween('transaction_timestamp', [$dayStart, $dayEnd])
            ->sum('gross_sales');
            
        return $total;
    }
    
    /**
     * Check if a new transaction would exceed daily limits
     * 
     * @param float $amount
     * @param Carbon $date
     * @return bool
     */
    public function wouldExceedDailyLimit($amount, Carbon $date)
    {
        if (!$this->max_daily_sales) {
            return false;
        }
        
        $currentTotal = $this->getDailySalesTotal($date);
        return ($currentTotal + $amount) > $this->max_daily_sales;
    }
}