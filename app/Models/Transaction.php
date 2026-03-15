<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;
use App\Jobs\Reporting\InvalidateCountCacheJob;

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
    public const VALIDATION_STATUS_VALID = 'VALID';
    public const VALIDATION_STATUS_PENDING = 'PENDING';
    public const VALIDATION_STATUS_FAILED = 'FAILED';
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
        // Legacy compatibility: some tests/seeders still mass-assign `base_amount`.
        // Mutator `setBaseAmountAttribute` maps this into `gross_sales`.
        'base_amount',
        'gross_sales',
        'vatable_sales',
        'vat_amount',
        'sc_vat_exempt_sales',
        'net_sales',
        'tax_exempt',
        'service_charge',
        'management_service_charge',
        'customer_code',
        'promo_status',
        'payload_checksum',
        'original_payload',
        'validation_status',
        'job_status',
        'last_error',
        'job_attempts',
        'completed_at',
        'discount_total',
        'submission_uuid',
        'submission_timestamp',
        'receipt_no',
        // Discount totals (denormalized for reporting)
        // Denormalized discounts (may be computed from transaction_adjustments)
        'promo_discount',
        'senior_discount',
        'pwd_discount',
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
     * Normalize receipt number on set: trim and coerce to string or null.
     *
     * @param mixed $value
     * @return void
     */
    public function setReceiptNoAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['receipt_no'] = null;
            return;
        }

        $this->attributes['receipt_no'] = $this->normalizeReceiptNo((string) $value);
    }

    /**
     * Normalize a receipt number deterministically for storage and lookup.
     * Rules:
     *  - Trim leading/trailing ASCII whitespace
     *  - Normalize Unicode (NFKC) when available
     *  - Remove non-printable/control characters
     *  - Collapse internal whitespace runs to a single space
     *  - Enforce a safe maximum length (128 chars)
     *  - Preserve leading zeros and all digits (do not cast to int)
     *
     * @param string $value
     * @return string
     */
    protected function normalizeReceiptNo(string $value): string
    {
        // 1) Trim ASCII whitespace
        $s = trim($value);

        // 2) Unicode normalization (NFKC) if available
        if (class_exists('\Normalizer')) {
            try {
                $norm = \Normalizer::normalize($s, \Normalizer::FORM_KC);
                if ($norm !== false && $norm !== null) {
                    $s = $norm;
                }
            } catch (\Throwable $e) {
                // ignore and fall back to original string
            }
        }

        // 3) Replace control chars (C0/C1) with a single space so we preserve
        // word boundaries when tabs/newlines are present, then collapse them
        // in the next step.
        $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s) ?? $s;

        // 4) Collapse internal whitespace runs to a single space
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        // 5) Enforce max length
        if (mb_strlen($s, 'UTF-8') > 128) {
            $s = mb_substr($s, 0, 128, 'UTF-8');
        }

        return $s;
    }

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
        // Use otherTaxSum helper which considers both tax rows and sc_vat_exempt_sales column
        $otherTaxSum = $this->otherTaxSum();

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
        $otherTaxSum = $this->otherTaxSum();

        return round($this->gross_sales - $otherTaxSum, 2);
    }

    /**
     * Return the sum of taxes considered 'other' (excluding VAT). This prefers explicit TransactionTax
     * rows when present; if no SC_VAT_EXEMPT_SALES tax row exists but the transaction has
     * a non-zero `sc_vat_exempt_sales` column (older ingestion paths), include that value so
     * calculations remain correct.
     *
     * @return float
     */
    public function otherTaxSum(): float
    {
        // Exclude VAT-related components and SC_VAT_EXEMPT_SALES from "other tax" summation.
        // These are either VAT itself, sales bases, or handled separately.
        $sum = $this->taxes()
            ->whereNotIn('tax_type', ['VAT', 'VAT_AMOUNT', 'VATABLE_SALES', 'SC_VAT_EXEMPT_SALES', 'VAT-EXEMPT', 'EXEMPT', 'VATEXEMPT'])
            ->sum('amount');

        return (float) ($sum ?? 0.0);
    }

    /**
     * Compatibility mutator: accept legacy `vat_exempt_sales` when tests or
     * older ingestion paths set that attribute. Map it into the canonical
     * `sc_vat_exempt_sales` column used by current calculations.
     */
    public function setVatExemptSalesAttribute($value)
    {
        $this->attributes['sc_vat_exempt_sales'] = $value;
    }

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        // Keep as 'string' (not 'datetime') to prevent Carbon from converting
        // the raw stored value (e.g. "2026-03-12T09:54:01Z") through the app
        // timezone, which would shift the time by +08:00 on serialization.
        // UI formatting is handled exclusively by dateFormatter.js.
        'transaction_timestamp' => 'string',

        'submission_timestamp' => 'datetime',
        'gross_sales' => 'decimal:2',
        'vatable_sales' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'sc_vat_exempt_sales' => 'decimal:2',
        'net_sales' => 'decimal:2',
        // Denormalized discount totals
        'promo_discount' => 'decimal:2',
        'senior_discount' => 'decimal:2',
        'pwd_discount' => 'decimal:2',
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
        'display_tenant_code',
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
            $expectedVat = round((float) $this->vatable_sales * 0.12, 2);
            $actualVat = round((float) $this->vat_amount, 2);
            // Allow small rounding differences (within 0.10) to tolerate
            // inconsistent VAT rounding from varied POS implementations.
            return abs($expectedVat - $actualVat) <= 0.10;
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

    /**
     * Find a transaction for voiding, scoped by tenant + terminal.
     *
     * Returns an array with keys:
     *  - transaction: Transaction|null
     *  - ambiguous: bool
     *  - identifier: 'transaction_id'|'receipt_no'|null
     *
     * This centralizes lookup/normalization so controllers can remain small.
     *
     * @param int|null $tenantId
     * @param int $terminalId
     * @param string|null $transactionId
     * @param string|null $receiptNo
     * @return array
     */
    public static function findForVoidByTerminal(?int $tenantId, int $terminalId, ?string $transactionId = null, ?string $receiptNo = null): array
    {
        // Prefer explicit transaction_id when supplied
        if (!empty($transactionId)) {
            // Prefer tenant-scoped lookup if tenant provided, but fall back to terminal-only lookup
            if ($tenantId !== null) {
                $tx = self::where('tenant_id', $tenantId)
                    ->where('terminal_id', $terminalId)
                    ->where('transaction_id', $transactionId)
                    ->first();
                if ($tx) {
                    return ['transaction' => $tx, 'ambiguous' => false, 'identifier' => 'transaction_id'];
                }
            }

            // Fallback: lookup by terminal + transaction_id (back-compat for records without tenant_id)
            $tx = self::where('terminal_id', $terminalId)
                ->where('transaction_id', $transactionId)
                ->first();

            return ['transaction' => $tx, 'ambiguous' => false, 'identifier' => 'transaction_id'];
        }

        if (empty($receiptNo)) {
            return ['transaction' => null, 'ambiguous' => false, 'identifier' => null];
        }

        $receiptNorm = trim((string) $receiptNo);

        // Prefer tenant-scoped lookup if tenant provided
        if ($tenantId !== null) {
            $query = self::where('tenant_id', $tenantId)
                ->where('terminal_id', $terminalId)
                ->where('receipt_no', $receiptNorm);
            $count = $query->count();
            if ($count > 1) {
                return ['transaction' => null, 'ambiguous' => true, 'identifier' => 'receipt_no'];
            }
            if ($count === 1) {
                return ['transaction' => $query->first(), 'ambiguous' => false, 'identifier' => 'receipt_no'];
            }
            // else fall through to terminal-only lookup
        }

        // Terminal-scoped fallback (covers legacy rows without tenant_id)
        $query = self::where('terminal_id', $terminalId)
            ->where('receipt_no', $receiptNorm);
        $count = $query->count();
        if ($count === 0) {
            return ['transaction' => null, 'ambiguous' => false, 'identifier' => 'receipt_no'];
        }
        if ($count > 1) {
            return ['transaction' => null, 'ambiguous' => true, 'identifier' => 'receipt_no'];
        }

        return ['transaction' => $query->first(), 'ambiguous' => false, 'identifier' => 'receipt_no'];
    }



    /**
     * Accessor: Display-friendly tenant code.
     * Priority:
     *  1) Tenant.customer_code (tenant-level identifier if present)
     *  2) Transaction.customer_code (legacy company-level code stored on transactions)
     *  3) Company.customer_code (via tenant relationship)
     *  4) 'UNKNOWN_TENANT'
     */
    public function getDisplayTenantCodeAttribute(): string
    {
        // Prefer tenant-level code if present
        $tenantCode = $this->tenant?->customer_code;
        if (!empty($tenantCode)) {
            return $tenantCode;
        }

        // Fall back to the transaction-level stored code
        if (!empty($this->customer_code)) {
            return $this->customer_code;
        }

        // Try company-level code via tenant relation
        $companyCode = $this->tenant?->company?->customer_code;
        if (!empty($companyCode)) {
            return $companyCode;
        }

        return 'UNKNOWN_TENANT';
    }

    /**
     * Compatibility accessor for legacy "base_amount" field.
     * Some older code/tests still reference $transaction->base_amount.
     * Map reads to the canonical gross_sales value so callers keep working.
     *
     * @return float|null
     */
    public function getBaseAmountAttribute()
    {
        return isset($this->gross_sales) ? (float) $this->gross_sales : null;
    }

    /**
     * Compatibility mutator for legacy "base_amount" input.
     * Maps mass-assigned base_amount => gross_sales so factories/tests that
     * still provide base_amount won't trigger SQL errors when the column
     * has been removed from the schema.
     *
     * @param mixed $value
     * @return void
     */
    public function setBaseAmountAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['gross_sales'] = null;
            return;
        }

        // Normalize numeric-like inputs and round to 2 decimals to match casts
        $this->attributes['gross_sales'] = round((float) $value, 2);
    }

    /**
     * Automatically synchronize transactions.customer_code from the related tenant.customer_code
     * for data hygiene. Applies on create and when tenant_id changes (or when customer_code is empty).
     */
    protected static function booted()
    {
        $sync = function (Transaction $tx) {
            // Prefer already-loaded relation to avoid an extra query
            if ($tx->relationLoaded('tenant') && $tx->tenant && !empty($tx->tenant->customer_code)) {
                $tx->customer_code = $tx->tenant->customer_code;
                return;
            }

            // Otherwise, look up by tenant_id if present
            if ($tx->tenant_id) {
                $tenant = Tenant::find($tx->tenant_id);
                if ($tenant && !empty($tenant->customer_code)) {
                    $tx->customer_code = $tenant->customer_code;
                }
            }
        };

        // Removed duplicate mutation logic from creating hook as per deadlock refactor checklist.

        static::saving(function (Transaction $tx) use ($sync) {
            if ($tx->isDirty('tenant_id') || empty($tx->customer_code)) {
                $sync($tx);
            }
        });

        // After write operations we increment tenant_version and optionally dispatch a targeted cache invalidation
        $handleWrite = function (Transaction $tx) {
            // Skip webapp-related cache/versioning and targeted invalidation when the
            // WebApp integration is disabled. This preserves the transaction write
            // behavior while stopping side-effects that relate to the external
            // WebApp system (forwarding, cache invalidation, etc.).
            if (!(bool) config('tsms.web_app.enabled', false)) {
                return;
            }

            $tenantId = $tx->tenant_id ?? optional($tx->terminal)->tenant_id ?? null;
            if ($tenantId) {
                try {
                    // Use atomic increment in cache (Redis) to bump tenant version
                    Cache::increment('webapp:tenant_version:' . $tenantId);
                } catch (\Throwable $e) {
                    Log::error('Failed to increment tenant_version', ['tenant' => $tenantId, 'error' => $e->getMessage()]);
                }
            }

            // If there is an HTTP request context, try to dispatch a targeted invalidation for that token
            try {
                $req = request();
                if ($req) {
                    $bearer = $req->bearerToken();
                    $sanctumId = null;
                    $ip = $req->ip();
                    if ($req->user()?->currentAccessToken()) {
                        $sanctumId = $req->user()->currentAccessToken()->getKey();
                    }

                    // Dispatch invalidation for the minimal filters we can derive (tenant-level)
                    $filters = ['tenant_id' => $tenantId];
                    Bus::dispatch(new InvalidateCountCacheJob($bearer, $sanctumId, $ip, $filters, $tenantId));
                }
            } catch (\Throwable $e) {
                // Log and continue; cache bump already protects correctness
                Log::error('Failed to dispatch InvalidateCountCacheJob', ['error' => $e->getMessage()]);
            }
        };

        static::created($handleWrite);
        static::updated($handleWrite);
        static::deleted($handleWrite);
    }
}