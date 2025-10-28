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
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'receipt_no')) {
                $table->string('receipt_no', 128)->nullable()->after('submission_timestamp');
                $table->index(['terminal_id', 'receipt_no'], 'transactions_terminal_receipt_idx');
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
            if (Schema::hasColumn('transactions', 'receipt_no')) {
                $table->dropIndex('transactions_terminal_receipt_idx');
                $table->dropColumn('receipt_no');
            }
        });
    }
};

