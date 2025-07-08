<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Check and add discount fields if they don't exist
            if (!Schema::hasColumn('transactions', 'senior_discount')) {
                $table->decimal('senior_discount', 15, 2)->nullable()->after('vat_amount');
            }
            if (!Schema::hasColumn('transactions', 'pwd_discount')) {
                $table->decimal('pwd_discount', 15, 2)->nullable()->after('senior_discount');
            }
            if (!Schema::hasColumn('transactions', 'vip_discount')) {
                $table->decimal('vip_discount', 15, 2)->nullable()->after('pwd_discount');
            }
            if (!Schema::hasColumn('transactions', 'employee_discount')) {
                $table->decimal('employee_discount', 15, 2)->nullable()->after('vip_discount');
            }
            if (!Schema::hasColumn('transactions', 'promo_with_approval')) {
                $table->decimal('promo_with_approval', 15, 2)->nullable()->after('employee_discount');
            }
            if (!Schema::hasColumn('transactions', 'promo_without_approval')) {
                $table->decimal('promo_without_approval', 15, 2)->nullable()->after('promo_with_approval');
            }
            
            // Check and add service charge fields if they don't exist
            if (!Schema::hasColumn('transactions', 'service_charge_distributed')) {
                $table->decimal('service_charge_distributed', 15, 2)->nullable();
            }
            if (!Schema::hasColumn('transactions', 'service_charge_retained')) {
                $table->decimal('service_charge_retained', 15, 2)->nullable();
            }
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