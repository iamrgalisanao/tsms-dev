<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('terminal_uid'); // Device identifier (e.g., MAC, UUID)
            $table->timestamp('registered_at')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            // Ensure a tenant cannot register the same terminal twice
            $table->unique(['tenant_id', 'terminal_uid']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('pos_terminals');
    }
};

