<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('security_events')) {
            return;
        }

        if (Schema::hasColumn('security_events', 'tenant_id')) {
            return;
        }

        Schema::table('security_events', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('security_events')) {
            return;
        }

        if (!Schema::hasColumn('security_events', 'tenant_id')) {
            return;
        }

        Schema::table('security_events', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
