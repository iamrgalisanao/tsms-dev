<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFieldsToTerminalTokens extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('terminal_tokens', function (Blueprint $table) {
            // Add new columns safely
            if (!Schema::hasColumn('terminal_tokens', 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable();
            }
            if (!Schema::hasColumn('terminal_tokens', 'is_revoked')) {
                $table->boolean('is_revoked')->default(false);
            }
            if (!Schema::hasColumn('terminal_tokens', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable();
            }
            if (!Schema::hasColumn('terminal_tokens', 'revoked_reason')) {
                $table->string('revoked_reason')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('terminal_tokens', function (Blueprint $table) {
            $table->dropColumn([
                'last_used_at',
                'is_revoked',
                'revoked_at',
                'revoked_reason'
            ]);
        });
    }
}
