<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            // Add new columns if they don't exist
            if (!Schema::hasColumn('integration_logs', 'log_type')) {
                $table->string('log_type')->nullable()->after('type');
            }
            if (!Schema::hasColumn('integration_logs', 'severity')) {
                $table->string('severity')->nullable()->after('log_type');
            }
            if (!Schema::hasColumn('integration_logs', 'validation_results')) {
                $table->json('validation_results')->nullable();
            }
            if (!Schema::hasColumn('integration_logs', 'metadata')) {
                $table->json('metadata')->nullable();
            }

            // Add indexes
            $table->index(['type', 'status']);
            $table->index('created_at');
            $table->index('log_type');
        });
    }

    public function down(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            // Remove indexes first
            $table->dropIndex(['type', 'status']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['log_type']);

            // Remove columns
            $table->dropColumn([
                'log_type',
                'severity',
                'validation_results',
                'metadata'
            ]);
        });
    }
};