<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'sc_vat_exempt_sales')) {
                $table->decimal('sc_vat_exempt_sales', 12, 2)->default(0.00)->after('vatable_sales');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'sc_vat_exempt_sales')) {
                $table->dropColumn('sc_vat_exempt_sales');
            }
        });
    }
};
