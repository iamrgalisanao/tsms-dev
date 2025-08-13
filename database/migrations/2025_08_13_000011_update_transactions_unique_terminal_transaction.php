<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                // NOTE:
                // We originally attempted to replace the single-column unique index on transaction_id with a
                // composite unique (terminal_id, transaction_id) to scope transaction ids per terminal.
                // However several child tables have foreign keys referencing transactions.transaction_id alone.
                // MySQL requires an index starting with the referenced column for those FKs; dropping the
                // single-column unique would break those constraints because the composite index has terminal_id first.
                // To avoid a cascade of FK/migration rewrites right now, we retain the original unique index and add
                // the composite unique (which becomes effectively redundant while the single-column unique exists).
                // Future refactor: migrate child FKs to reference transactions.id (PK) or include terminal_id then safely drop.
                try { $table->unique(['terminal_id','transaction_id'], 'trx_terminal_transaction_unique'); } catch (\Throwable $e) {}
            });
        }
    }
    public function down(): void {
        if (Schema::hasTable('transactions')) {
            Schema::table('transactions', function (Blueprint $table) {
                try { $table->dropUnique('trx_terminal_transaction_unique'); } catch (\Throwable $e) {}
                try { $table->unique('transaction_id'); } catch (\Throwable $e) {}
            });
        }
    }
};
