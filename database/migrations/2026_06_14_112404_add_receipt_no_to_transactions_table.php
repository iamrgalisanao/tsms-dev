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
        if (Schema::hasColumn('transactions', 'receipt_no')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('receipt_no', 128)->nullable()->after('transaction_id');
            
            // Add index for search optimization since receipt_no is commonly used in filters
            $table->index('receipt_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('transactions', 'receipt_no')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['receipt_no']);
            $table->dropColumn('receipt_no');
        });
    }
};
