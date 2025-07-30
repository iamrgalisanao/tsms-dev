<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    // Validation statuses
    public const VALIDATION_STATUS_VALID   = 'VALID';
    public const VALIDATION_STATUS_PENDING = 'PENDING';
    public const VALIDATION_STATUS_FAILED  = 'FAILED';
    // Add more as needed
    use HasFactory;

    use HasFactory;
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'transactions';
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'terminal_id', 
        'transaction_id',
        'hardware_id',
        'transaction_timestamp',
        'base_amount',
        'customer_code',
        'payload_checksum',
        'validation_status',
        'submission_uuid',
        'submission_timestamp',
        'created_at',
        'updated_at',
    ];

    /**
     * Accessor for the latest job status from transaction_jobs
     *
     * @return string|null
     */
    public function getLatestJobStatusAttribute()
    {
        $latestJob = $this->jobs()->latest('created_at')->first();
        return $latestJob ? $latestJob->job_status : self::JOB_STATUS_QUEUED;
    }

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'transaction_timestamp' => 'datetime',
        'submission_timestamp' => 'datetime',
        'base_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    
    // Add job status constants
    const JOB_STATUS_QUEUED = 'QUEUED';
    const JOB_STATUS_PROCESSING = 'PROCESSING';
    const JOB_STATUS_COMPLETED = 'COMPLETED';
    const JOB_STATUS_FAILED = 'FAILED';

    /**
     * Promo status constants
     */
    const PROMO_STATUS_WITH_APPROVAL = 'WITH_APPROVAL';
    const PROMO_STATUS_WITHOUT_APPROVAL = 'WITHOUT_APPROVAL';

    /**
     * Get the terminal that made this transaction.
     */
    public function terminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }

    /**
     * Get the tenant that owns this transaction.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Get the processing history for this transaction.
     */
    public function processingHistory()
    {
        return $this->hasMany(TransactionHistory::class)->orderBy('created_at', 'desc');
    }
    
    /**
     * Check if this transaction occurred during store operating hours
     * 
     * @return bool
     */
    public function isWithinOperatingHours()
    {
        // If trade_name is used instead of store, you may need to implement logic
        // based on trade_name and a business hours lookup, or always return true/false.
        // For now, we return true as a placeholder.
        return true;
    }
    
    /**
     * Calculate and validate VAT amount
     * 
     * @return bool
     */
    public function validateVatAmount()
    {
        if ($this->tax_exempt) {
            return $this->vat_amount === 0;
        }
        if ($this->vatable_sales > 0) {
            $expectedVat = round($this->vatable_sales * 0.12, 2);
            $actualVat = round($this->vat_amount, 2);
            // Allow small rounding differences (within 0.02)
            return abs($expectedVat - $actualVat) <= 0.02;
        }
        return true;
    }
    
    /**
     * Check if this transaction is a duplicate of another one
     * 
     * @return bool
     */
    public function isDuplicate()
    {
        return Transaction::where('transaction_id', $this->transaction_id)
            ->where('terminal_id', $this->terminal_id)
            ->where('id', '!=', $this->id)
            ->exists();
    }
    
    /**
     * Calculate expected net sales from gross sales and VAT
     * 
     * @return float
     */
    public function calculateExpectedNetSales()
    {
        return round($this->gross_sales - $this->vat_amount, 2);
    }

    public function adjustments()
    {
        return $this->hasMany(TransactionAdjustment::class, 'transaction_id', 'transaction_id');
    }

    public function taxes()
    {
        return $this->hasMany(TransactionTax::class, 'transaction_id', 'transaction_id');
    }

    public function jobs()
    {
        return $this->hasMany(TransactionJob::class, 'transaction_id', 'transaction_id');
    }

    public function validations()
    {
        return $this->hasMany(TransactionValidation::class, 'transaction_id', 'transaction_id');
    }

    /**
     * Get the webapp forwarding record for this transaction
     */
    public function webappForward()
    {
        return $this->hasOne(\App\Models\WebappTransactionForward::class);
    }

    /**
     * Check if this transaction has been forwarded to webapp
     */
    public function isForwardedToWebapp(): bool
    {
        return $this->webappForward && $this->webappForward->status === \App\Models\WebappTransactionForward::STATUS_COMPLETED;
    }

    /**
     * Check if this transaction is pending webapp forwarding
     */
    public function isPendingWebappForward(): bool
    {
        return !$this->webappForward || $this->webappForward->status === \App\Models\WebappTransactionForward::STATUS_PENDING;
    }

    /**
     * Check if this transaction is eligible for webapp forwarding
     */
    public function isEligibleForWebappForward(): bool
    {
        return $this->validation_status === 'VALID' && $this->isPendingWebappForward();
    }
}