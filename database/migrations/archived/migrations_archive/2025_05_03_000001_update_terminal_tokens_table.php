<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateTerminalTokensTable extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('terminal_tokens', function (Blueprint $table) {
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->renameColumn('revoked', 'is_revoked');
        });
    }

    public function down(): void
    {
        Schema::table('terminal_tokens', function (Blueprint $table) {
            $table->renameColumn('is_revoked', 'revoked');
            $table->dropColumn(['last_used_at', 'revoked_at', 'revoked_reason']);
        });
    }
};
