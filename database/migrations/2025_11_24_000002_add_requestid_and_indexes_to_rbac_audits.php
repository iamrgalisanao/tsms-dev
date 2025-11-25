<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rbac_audits', function (Blueprint $table) {
            if (!Schema::hasColumn('rbac_audits', 'request_id')) {
                $table->string('request_id')->nullable()->after('actor_id');
            }
            if (!Schema::hasColumn('rbac_audits', 'meta')) {
                // meta already exists from initial migration; noop
            }

            // Add indexes for faster queries
            $table->index(['actor_id'], 'rbac_audits_actor_id_index');
            $table->index(['event_type'], 'rbac_audits_event_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('rbac_audits', function (Blueprint $table) {
            if (Schema::hasColumn('rbac_audits', 'request_id')) {
                $table->dropColumn('request_id');
            }
            $table->dropIndex('rbac_audits_actor_id_index');
            $table->dropIndex('rbac_audits_event_type_index');
        });
    }
};
