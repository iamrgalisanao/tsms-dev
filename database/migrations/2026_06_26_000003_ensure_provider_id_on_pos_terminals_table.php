<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_terminals', 'provider_id')) {
                $table->foreignId('provider_id')
                    ->nullable()
                    ->after('tenant_id')
                    ->constrained('pos_providers')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: this migration may run on databases
        // where the column already existed outside the tracked migration set.
    }
};
