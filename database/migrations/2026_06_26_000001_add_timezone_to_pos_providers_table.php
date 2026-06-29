<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add timezone column to pos_providers so each provider's local
     * transaction_timestamp can be normalized to true UTC on ingest.
     *
     * Default is 'Asia/Manila' and is only used when timestamp_mode is
     * configured for local wall-clock timestamps.
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
