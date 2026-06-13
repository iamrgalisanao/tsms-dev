<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['tenants', 'pos_terminals'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'activity_suppressed_until')) {
                    $column = $table->timestamp('activity_suppressed_until')->nullable();
                    if (Schema::hasColumn($tableName, 'activity_monitoring_notes')) {
                        $column->after('activity_monitoring_notes');
                    }
                }

                if (! Schema::hasColumn($tableName, 'activity_suppression_reason')) {
                    $table->string('activity_suppression_reason', 500)->nullable()->after('activity_suppressed_until');
                }

                if (! Schema::hasColumn($tableName, 'activity_suppressed_by')) {
                    $table->foreignId('activity_suppressed_by')->nullable()->after('activity_suppression_reason')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn($tableName, 'activity_suppressed_at')) {
                    $table->timestamp('activity_suppressed_at')->nullable()->after('activity_suppressed_by');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['pos_terminals', 'tenants'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'activity_suppressed_by')) {
                    $table->dropConstrainedForeignId('activity_suppressed_by');
                }

                foreach (['activity_suppressed_at', 'activity_suppression_reason', 'activity_suppressed_until'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
