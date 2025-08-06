<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->enum('transaction_type', ['SALE', 'VOID', 'REFUND', 'ADJUSTMENT'])->default('SALE')->after('id');
            $table->string('original_transaction_id', 191)->nullable()->after('transaction_id');
            $table->decimal('refund_amount', 12, 2)->nullable()->after('base_amount');
            $table->text('refund_reason')->nullable()->after('refund_amount');
            $table->boolean('is_refunded')->default(false)->after('refund_reason');
        });
        // Indexes for new fields
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('transaction_type', 'idx_transactions_type');
            $table->index('original_transaction_id', 'idx_transactions_original');
            $table->index('is_refunded', 'idx_transactions_refunded');
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
            $table->dropIndex('idx_transactions_type');
            $table->dropIndex('idx_transactions_original');
            $table->dropIndex('idx_transactions_refunded');
            $table->dropColumn(['transaction_type', 'original_transaction_id', 'refund_amount', 'refund_reason', 'is_refunded']);
        });
    }
};
