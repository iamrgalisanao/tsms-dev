<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add started_at to transaction_validations if missing (some runtime paths insert this)
        if (Schema::hasTable('transaction_validations') && ! Schema::hasColumn('transaction_validations', 'started_at')) {
            Schema::table('transaction_validations', function (Blueprint $table) {
                // nullable timestamp to be safe for older rows
                $table->timestamp('started_at')->nullable()->after('status_code');
            });
        }

        // Add tenant_id to security_reports - some services include tenant context when creating reports
        if (Schema::hasTable('security_reports') && ! Schema::hasColumn('security_reports', 'tenant_id')) {
            Schema::table('security_reports', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id', 'idx_security_reports_tenant_id');
            });
            // Attempt to add FK if tenants table exists, but ignore failures
            try {
                if (Schema::hasTable('tenants')) {
                    Schema::table('security_reports', function (Blueprint $table) {
                        $table->foreign('tenant_id', 'fk_security_reports_tenant')->references('id')->on('tenants')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
                // noop - keep migration idempotent and tolerant
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transaction_validations') && Schema::hasColumn('transaction_validations', 'started_at')) {
            Schema::table('transaction_validations', function (Blueprint $table) {
                $table->dropColumn('started_at');
            });
        }

        if (Schema::hasTable('security_reports') && Schema::hasColumn('security_reports', 'tenant_id')) {
            try {
                Schema::table('security_reports', function (Blueprint $table) {
                    // drop FK if exists
                    try { $table->dropForeign('fk_security_reports_tenant'); } catch (\Throwable $e) {}
                    $table->dropIndex('idx_security_reports_tenant_id');
                    $table->dropColumn('tenant_id');
                });
            } catch (\Throwable $e) {
                // ignore - down is best-effort
            }
        }
    }
};
