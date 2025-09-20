<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pos_terminals')) {
            return;
        }
        Schema::table('pos_terminals', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_terminals', 'last_sale_at')) {
                $table->timestamp('last_sale_at')->nullable()->after('last_seen_at');
                $table->index(['tenant_id', 'last_sale_at'], 'pos_terminals_tenant_last_sale_idx');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('pos_terminals')) {
            return;
        }
        Schema::table('pos_terminals', function (Blueprint $table) {
            if (Schema::hasColumn('pos_terminals', 'last_sale_at')) {
                $table->dropIndex('pos_terminals_tenant_last_sale_idx');
                $table->dropColumn('last_sale_at');
            }
        });
    }
};
