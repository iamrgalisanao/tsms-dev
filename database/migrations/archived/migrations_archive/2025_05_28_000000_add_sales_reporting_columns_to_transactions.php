<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Add specific discount fields
            $table->decimal('senior_discount', 15, 2)->nullable()->after('vat_amount');
            $table->decimal('pwd_discount', 15, 2)->nullable()->after('senior_discount');
            $table->decimal('vip_discount', 15, 2)->nullable()->after('pwd_discount');
            $table->decimal('employee_discount', 15, 2)->nullable()->after('vip_discount');
            $table->decimal('promo_with_approval', 15, 2)->nullable()->after('employee_discount');
            $table->decimal('promo_without_approval', 15, 2)->nullable()->after('promo_with_approval');
            
            // Add service charge distribution fields
            $table->decimal('service_charge_distributed', 15, 2)->nullable()->after('service_charge');
            $table->decimal('service_charge_retained', 15, 2)->nullable()->after('service_charge_distributed');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'senior_discount',
                'pwd_discount', 
                'vip_discount',
                'employee_discount',
                'promo_with_approval',
                'promo_without_approval',
                'service_charge_distributed',
                'service_charge_retained'
            ]);
        });
    }
};