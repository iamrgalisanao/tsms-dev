<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * NOTE: This migration uses column type changes and requires the
     * doctrine/dbal package to be available when running locally or in CI.
     *
     * Goal:
     * - Ensure the `transaction_timestamp` column supports millisecond
     *   precision (timestamp(3) / datetime(3)) so forwards consistently
     *   format to ISO with milliseconds.
     * - Backfill NULL transaction_timestamp values using created_at
     *   (safe fallback) so forwarders can rely on a value.
     */
    public function up(): void
    {
        // Make the column millisecond-precision where supported.
        if (Schema::hasColumn('transactions', 'transaction_timestamp')) {
            // Changing column precision requires doctrine/dbal. Teams running this
            // migration must ensure doctrine/dbal is installed in their environment.
            Schema::table('transactions', function (Blueprint $table) {
                // Use timestamp with 3 digits of fractional seconds where possible.
                // Laravel will translate to the appropriate platform type.
                $table->timestamp('transaction_timestamp', 3)->nullable()->change();
            });

            // Backfill any NULL transaction_timestamp rows from created_at so
            // downstream forwarders have a value. We store using the DB native
            // timestamp type; the application will format this to ISO with ms
            // when building envelopes.
            DB::beginTransaction();
            try {
                DB::statement("UPDATE transactions SET transaction_timestamp = created_at WHERE transaction_timestamp IS NULL");
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }
        }
    }

    public function down(): void
    {
        // Revert to prior precision (no fractional seconds). This is best-effort.
        if (Schema::hasColumn('transactions', 'transaction_timestamp')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->timestamp('transaction_timestamp')->nullable(false)->change();
            });
        }
    }
};
