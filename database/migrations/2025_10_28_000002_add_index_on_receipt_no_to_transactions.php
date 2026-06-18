<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     *
     * Adds a composite index for tenant_id + terminal_id + receipt_no to
     * speed up lookup when voiding by receipt_no.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['tenant_id', 'terminal_id', 'receipt_no'], 'idx_tx_tenant_terminal_receipt');
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
            $table->dropIndex('idx_tx_tenant_terminal_receipt');
        });
    }
};
