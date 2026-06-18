<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'logged_at')) {
                // Place after metadata where possible
                $table->timestamp('logged_at')->nullable()->useCurrent()->after('metadata');
                // Create a named index so we can drop it reliably in down()
                $table->index('logged_at', 'idx_audit_logs_logged_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (Schema::hasColumn('audit_logs', 'logged_at')) {
                // Drop index if exists (safe-guard)
                try {
                    $table->dropIndex('idx_audit_logs_logged_at');
                } catch (\Throwable $_) {
                    // ignore: index may not exist under that name
                }
                $table->dropColumn('logged_at');
            }
        });
    }
};
