<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    // ...existing code...
    // Scopes for common filtering patterns
    public function scopeValidOnly($query)
    {
        return $query->where('validation_status', self::VALIDATION_STATUS_VALID);
    }

    public function scopePending($query)
    {
        return $query->where('validation_status', self::VALIDATION_STATUS_PENDING);
    }

    public function scopeFailed($query)
    {
        return $query->where('validation_status', self::VALIDATION_STATUS_FAILED);
    }

    public function scopeQueued($query)
    {
        return $query->where('job_status', self::JOB_STATUS_QUEUED);
    }

    /**
     * Determine if this transaction appears stale (pending longer than threshold minutes)
     */
    public function isPendingStale(int $thresholdMinutes): bool
    {
        if ($this->validation_status !== self::VALIDATION_STATUS_PENDING) {
            return false;
        }
        return $this->created_at && $this->created_at->lt(now()->subMinutes($thresholdMinutes));
    }

    /**
     * Mark this transaction as voided.
     *
     * @param string|null $reason
     * @return void
     */
    public function void($reason = null)
    {
        $this->voided_at = now();
        $this->void_reason = $reason;
        $this->save();
    }

    /**
     * Check if this transaction is voided.
     *
     * @return bool
     */
    public function isVoided(): bool
    {
        return !empty($this->voided_at);
    }

    /**
     * Check if this transaction is refunded
     *
     * @return bool
     */
    public function isRefunded(): bool
    {
        return $this->refund_status === 'REFUNDED' && $this->refund_amount > 0;
    }

    /**
     * Check if this transaction can be refunded
     *
     * @return bool
     */
    public function canRefund(): bool
    {
        // Only allow refund if not already refunded and gross_sales is positive
        return !$this->isRefunded() && $this->gross_sales > 0;
    }
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
        'gross_sales',
        'vatable_sales',
        'vat_amount',
        'net_sales',
        'tax_exempt',
        'service_charge',
        'management_service_charge',
        'customer_code',
        'promo_status',
        'payload_checksum',
        'validation_status',
    'job_status',
    'last_error',
    'job_attempts',
    'completed_at',
        'submission_uuid',
        'submission_timestamp',
        'refund_status',
        'refund_amount',
        'refund_reason',
        'refund_reference_id',
        'refund_processed_at',
        'voided_at',
        'void_reason',
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
        $latestJob = $this->jobs()->latest('updated_at')->first();
        return $latestJob ? $latestJob->job_status : self::JOB_STATUS_QUEUED;
    }

    /**
     * Read-only accessor for net amount following simplified formula:
     * net_sales = gross_sales - other_tax (excluding VAT)
     *
     * @return float
     */
    public function getNetAmountAttribute()
    {
        // Calculate other_tax sum (excluding VAT) from relationship
        $otherTaxSum = $this->taxes()
            ->where('tax_type', '!=', 'VAT')
            ->sum('amount') ?? 0;

        // Simplified formula: net_sales = gross_sales - other_tax
        return round($this->gross_sales - $otherTaxSum, 2);
    }

    /**
     * Accessor to validate that net_sales follows the formula: net_sales = gross_sales - other_tax
     *
     * @return float
     */
    public function getCalculatedNetSalesAttribute()
    {
        // Calculate other_tax sum (excluding VAT) from relationship
        $otherTaxSum = $this->taxes()
            ->where('tax_type', '!=', 'VAT')
            ->sum('amount') ?? 0;

        return round($this->gross_sales - $otherTaxSum, 2);
    }

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'transaction_timestamp' => 'datetime',
        'submission_timestamp' => 'datetime',
        'gross_sales' => 'decimal:2',
        'vatable_sales' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'net_sales' => 'decimal:2',
        'service_charge' => 'decimal:2',
        'management_service_charge' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'refund_processed_at' => 'datetime',
        'voided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'tax_exempt' => 'boolean',
    ];
    
    /**
     * Attributes to append to the model's array / JSON form.
     * Exposes computed values following new formulas to API consumers.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'net_amount',
        'calculated_net_sales',
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
    return $this->hasMany(TransactionAdjustment::class, 'transaction_pk', 'id');
    }

    public function taxes()
    {
    return $this->hasMany(TransactionTax::class, 'transaction_pk', 'id');
    }

    public function jobs()
    {
    return $this->hasMany(TransactionJob::class, 'transaction_pk', 'id');
    }

    public function validations()
    {
    return $this->hasMany(TransactionValidation::class, 'transaction_pk', 'id');
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

    /**
     * Submission envelope relationship
     */
    public function submission()
    {
        return $this->belongsTo(TransactionSubmission::class, 'submission_uuid', 'submission_uuid');
    }
}