<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('transactions')) {
            // First drop foreign keys in child tables referencing transactions.transaction_id
            $children = [
                'transaction_taxes',
                'transaction_adjustments',
                'transaction_jobs',
                'transaction_validations',
            ];
            foreach ($children as $child) {
                if (!Schema::hasTable($child)) { continue; }
                Schema::table($child, function (Blueprint $table) use ($child) {
                    $fkName = $child . '_transaction_id_foreign';
                    try { $table->dropForeign($fkName); } catch (\Throwable $e) {}
                    if (Schema::hasColumn($child, 'transaction_id')) {
                        try { $table->dropColumn('transaction_id'); } catch (\Throwable $e) {}
                    }
                });
            }
            Schema::table('transactions', function (Blueprint $table) {
                // Drop single-column unique on transaction_id if still present (composite already exists from earlier migration)
                try { $table->dropUnique('transactions_transaction_id_unique'); } catch (\Throwable $e) {}
            });
        }
    }
    public function down(): void {
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                // Recreate single-column unique if absent
                try { $table->unique('transaction_id'); } catch (\Throwable $e) {}
            });
        }
    }
};
