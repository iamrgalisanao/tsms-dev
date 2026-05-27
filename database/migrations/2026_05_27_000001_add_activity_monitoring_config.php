<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'activity_monitoring_enabled')) {
                $column = $table->boolean('activity_monitoring_enabled')->default(true);
                if (Schema::hasColumn('tenants', 'accept_with_issues')) {
                    $column->after('accept_with_issues');
                }
            }

            if (! Schema::hasColumn('tenants', 'activity_threshold_minutes')) {
                $table->unsignedInteger('activity_threshold_minutes')->nullable()->after('activity_monitoring_enabled');
            }

            if (! Schema::hasColumn('tenants', 'activity_monitoring_notes')) {
                $table->string('activity_monitoring_notes', 500)->nullable()->after('activity_threshold_minutes');
            }
        });

        Schema::table('pos_terminals', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_terminals', 'activity_monitoring_enabled')) {
                $column = $table->boolean('activity_monitoring_enabled')->default(true);
                if (Schema::hasColumn('pos_terminals', 'heartbeat_threshold')) {
                    $column->after('heartbeat_threshold');
                }
            }

            if (! Schema::hasColumn('pos_terminals', 'activity_threshold_minutes')) {
                $table->unsignedInteger('activity_threshold_minutes')->nullable()->after('activity_monitoring_enabled');
            }

            if (! Schema::hasColumn('pos_terminals', 'activity_monitoring_notes')) {
                $table->string('activity_monitoring_notes', 500)->nullable()->after('activity_threshold_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            foreach (['activity_monitoring_notes', 'activity_threshold_minutes', 'activity_monitoring_enabled'] as $column) {
                if (Schema::hasColumn('pos_terminals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            foreach (['activity_monitoring_notes', 'activity_threshold_minutes', 'activity_monitoring_enabled'] as $column) {
                if (Schema::hasColumn('tenants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
