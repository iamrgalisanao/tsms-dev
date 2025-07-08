<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('terminal_tokens')) {
            Schema::create('terminal_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('terminal_id')->constrained('pos_terminals')->onDelete('cascade');
                $table->string('access_token');
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->boolean('is_revoked')->default(false);
                $table->timestamp('revoked_at')->nullable();
                $table->string('revoked_reason')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_tokens');
    }
};
