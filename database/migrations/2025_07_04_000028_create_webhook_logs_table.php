<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('terminal_id')->constrained('pos_terminals')->onDelete('cascade');
            $table->string('event_type');
            $table->json('payload');
            $table->string('status');
            $table->timestamp('received_at');
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('webhook_logs');
    }
};