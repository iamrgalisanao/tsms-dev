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
            if (!Schema::hasColumn('transactions', 'promo_discount')) {
                $table->decimal('promo_discount', 14, 2)->default(0)->after('refund_amount');
            }
            if (!Schema::hasColumn('transactions', 'senior_discount')) {
                $table->decimal('senior_discount', 14, 2)->default(0)->after('promo_discount');
            }
            if (!Schema::hasColumn('transactions', 'pwd_discount')) {
                $table->decimal('pwd_discount', 14, 2)->default(0)->after('senior_discount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'pwd_discount')) {
                $table->dropColumn('pwd_discount');
            }
            if (Schema::hasColumn('transactions', 'senior_discount')) {
                $table->dropColumn('senior_discount');
            }
            if (Schema::hasColumn('transactions', 'promo_discount')) {
                $table->dropColumn('promo_discount');
            }
        });
    }
};
