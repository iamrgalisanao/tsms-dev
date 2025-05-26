<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddValidationFieldsToTransactions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Check if columns already exist to avoid migration errors
        Schema::table('transactions', function (Blueprint $table) {
            // Service charge and discount fields
            if (!Schema::hasColumn('transactions', 'service_charge')) {
                $table->decimal('service_charge', 12, 2)->nullable()->after('vat_amount');
            }
            
            if (!Schema::hasColumn('transactions', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->nullable()->after('service_charge');
            }
            
            if (!Schema::hasColumn('transactions', 'discount_auth_code')) {
                $table->string('discount_auth_code')->nullable()->after('discount_amount');
            }
            
            if (!Schema::hasColumn('transactions', 'promo_code')) {
                $table->string('promo_code')->nullable()->after('discount_auth_code');
            }
            
            // Tax exemption fields
            if (!Schema::hasColumn('transactions', 'tax_exempt')) {
                $table->boolean('tax_exempt')->default(false)->after('promo_code');
            }
            
            if (!Schema::hasColumn('transactions', 'tax_exempt_id')) {
                $table->string('tax_exempt_id')->nullable()->after('tax_exempt');
            }
            
            // Transaction sequencing support
            if (!Schema::hasColumn('transactions', 'sequence_number')) {
                $table->integer('sequence_number')->nullable()->after('tax_exempt_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $columns = [
                'service_charge', 
                'discount_amount',
                'discount_auth_code',
                'promo_code',
                'tax_exempt',
                'tax_exempt_id',
                'sequence_number'
            ];
            
            // Only drop columns that exist
            foreach ($columns as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}