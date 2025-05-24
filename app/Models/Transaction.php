<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
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
        'store_name',
        'hardware_id',
        'machine_number',
        'transaction_timestamp',
        'gross_sales',
        'net_sales',
        'vatable_sales',
        'vat_exempt_sales',
        'vat_amount',
        'promo_discount_amount',
        'promo_status',
        'discount_total',
        'discount_details',
        'other_tax',
        'management_service_charge',
        'employee_service_charge',
        'transaction_count',
        'payload_checksum',
        'validation_status',
        'error_code',
        'job_status',
        'last_error',
        'job_attempts',
        'completed_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'transaction_timestamp' => 'datetime',
        'gross_sales' => 'decimal:2',
        'net_sales' => 'decimal:2',
        'vatable_sales' => 'decimal:2',
        'vat_exempt_sales' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'promo_discount_amount' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'discount_details' => 'json',
        'other_tax' => 'decimal:2',
        'management_service_charge' => 'decimal:2', 
        'employee_service_charge' => 'decimal:2',
        'transaction_count' => 'integer',
        'job_attempts' => 'integer',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'validation_details' => 'array',
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
}