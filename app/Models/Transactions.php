<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transactions extends Model
{
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
    ];
    
}
