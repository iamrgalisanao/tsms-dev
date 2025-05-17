<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_terminals', 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }
            
            if (!Schema::hasColumn('pos_terminals', 'is_revoked')) {
                $table->boolean('is_revoked')->default(false);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            if (Schema::hasColumn('pos_terminals', 'expires_at')) {
                $table->dropColumn('expires_at');
            }
            
            if (Schema::hasColumn('pos_terminals', 'is_revoked')) {
                $table->dropColumn('is_revoked');
            }
        });
    }
};
