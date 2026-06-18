<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration is defensive: it only adds columns if they don't already exist.
     */
    public function up(): void
    {
        // Add `name` to tenants if missing
        if (! Schema::hasColumn('tenants', 'name')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->string('name')->nullable()->after('trade_name');
            });
        }

        // Add `status` to pos_terminals if missing
        if (! Schema::hasColumn('pos_terminals', 'status')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                $table->string('status')->nullable()->after('notifications_enabled');
            });
        }

        // Add `security_report_template_id` to security_reports if missing
        if (! Schema::hasColumn('security_reports', 'security_report_template_id')) {
            Schema::table('security_reports', function (Blueprint $table) {
                $table->unsignedBigInteger('security_report_template_id')->nullable()->after('tenant_id');
            });
        }

        // Add tenant_id to users if missing (some tests insert users with tenant_id)
        if (! Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('is_active');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'name')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }

        if (Schema::hasColumn('pos_terminals', 'status')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }

        if (Schema::hasColumn('security_reports', 'security_report_template_id')) {
            Schema::table('security_reports', function (Blueprint $table) {
                $table->dropColumn('security_report_template_id');
            });
        }

        if (Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }
    }
};
