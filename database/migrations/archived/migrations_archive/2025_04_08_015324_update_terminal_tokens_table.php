<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('terminal_tokens', function (Blueprint $table) {
            // Add index for terminal_id if not yet added
            $table->index('terminal_id');

            // Add issued_at and expires_at if not yet present
            if (!Schema::hasColumn('terminal_tokens', 'issued_at')) {
                $table->timestamp('issued_at')->nullable();
            }

            if (!Schema::hasColumn('terminal_tokens', 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }

            // Add revoked column if missing
            if (!Schema::hasColumn('terminal_tokens', 'revoked')) {
                $table->boolean('revoked')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('terminal_tokens', function (Blueprint $table) {
            $table->dropIndex(['terminal_id']);
            $table->dropColumn(['issued_at', 'expires_at', 'revoked']);
        });
    }
};
