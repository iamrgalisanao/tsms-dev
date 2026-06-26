<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add timezone column to pos_providers so each provider's
     * incoming transaction_timestamp can be converted to Manila time.
     *
     * Default is 'Asia/Manila' — providers already sending Manila time
     * require no configuration change. Providers sending UTC (or any
     * other timezone) are converted on ingest.
     */
    public function up(): void
    {
        Schema::table('pos_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_providers', 'timezone')) {
                $table->string('timezone', 64)
                    ->default('Asia/Manila')
                    ->after(Schema::hasColumn('pos_providers', 'code') ? 'code' : 'name');
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: this migration may run on databases
        // where the column already existed outside the tracked migration set.
    }
};
