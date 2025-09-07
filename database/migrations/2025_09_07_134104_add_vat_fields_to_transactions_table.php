<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('vatable_sales', 12, 2)->nullable()->after('gross_sales');
            $table->decimal('vat_amount', 12, 2)->nullable()->after('vatable_sales');
            $table->decimal('net_sales', 12, 2)->nullable()->after('vat_amount');
            $table->boolean('tax_exempt')->default(false)->after('net_sales');
            $table->decimal('service_charge', 12, 2)->nullable()->after('tax_exempt');
            $table->decimal('management_service_charge', 12, 2)->nullable()->after('service_charge');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['vatable_sales', 'vat_amount', 'net_sales', 'tax_exempt', 'service_charge', 'management_service_charge']);
        });
    }
};
