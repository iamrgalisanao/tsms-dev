<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('transactions', 'discount_total')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->decimal('discount_total', 15, 2)->nullable()->after('vat_amount');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('transactions', 'discount_total')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropColumn('discount_total');
            });
        }
    }
};
