<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'refund_status')) {
                $table->string('refund_status', 50)->nullable()->after('refund_reason');
            }

            if (! Schema::hasColumn('transactions', 'refund_reference_id')) {
                $table->string('refund_reference_id', 191)->nullable()->after('refund_status');
            }

            if (! Schema::hasColumn('transactions', 'refund_processed_at')) {
                $table->timestamp('refund_processed_at')->nullable()->after('refund_reference_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $columns = [];

            foreach (['refund_processed_at', 'refund_reference_id', 'refund_status'] as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
