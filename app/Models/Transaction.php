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
        'transaction_id',
        'transaction_timestamp',
        'gross_sales',
        'net_sales',
        'vatable_sales',
        'vat_exempt_sales',
        'vat_amount',
        'transaction_count',
        'payload_checksum',
        'hardware_id',
        'machine_number',
        'store_name',
        'promo_discount_amount',
        'promo_status',
        'discount_total',
        'discount_details',
        'other_tax',
        'management_service_charge',
        'employee_service_charge',
        'validation_status',
        'error_code',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'transaction_timestamp' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'gross_sales' => 'decimal:2',
        'net_sales' => 'decimal:2',
        'vatable_sales' => 'decimal:2',
        'vat_exempt_sales' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'promo_discount_amount' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'other_tax' => 'decimal:2',
        'management_service_charge' => 'decimal:2',
        'employee_service_charge' => 'decimal:2',
        'transaction_count' => 'integer',
        'discount_details' => 'array',
    ];
    
    /**
     * Get the terminal that owns the transaction.
     */
    public function posTerminal()
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }
    
    /**
     * Get the tenant that owns the transaction.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}