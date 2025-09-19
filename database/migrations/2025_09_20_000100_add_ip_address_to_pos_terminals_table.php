<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('pos_terminals')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_terminals', 'ip_address')) {
                    $table->string('ip_address', 45)->nullable()->after('machine_number');
                }
                if (!Schema::hasColumn('pos_terminals', 'last_ip_at')) {
                    $table->timestamp('last_ip_at')->nullable()->after('last_seen_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_terminals')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                if (Schema::hasColumn('pos_terminals', 'ip_address')) {
                    $table->dropColumn('ip_address');
                }
                if (Schema::hasColumn('pos_terminals', 'last_ip_at')) {
                    $table->dropColumn('last_ip_at');
                }
            });
        }
    }
};
