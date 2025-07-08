<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->string('serial_number', 191)->unique();
            $table->string('machine_number', 191)->nullable();
            $table->boolean('supports_guest_count')->default(false);
            $table->foreignId('pos_type_id')->nullable()->constrained('pos_types')->nullOnDelete()->onUpdate('cascade');
            $table->foreignId('integration_type_id')->nullable()->constrained('integration_types')->nullOnDelete()->onUpdate('cascade');
            $table->foreignId('auth_type_id')->nullable()->constrained('auth_types')->nullOnDelete()->onUpdate('cascade');
            $table->foreignId('status_id')->constrained('terminal_statuses')->onUpdate('cascade')->onDelete('restrict');
            $table->dateTime('expires_at')->nullable();
            $table->timestamp('registered_at');
            $table->timestamp('last_seen_at')->nullable()->comment('Last time terminal pushed a transaction');
            $table->unsignedInteger('heartbeat_threshold')->default(300)->comment('Seconds before marking inactive');
            $table->timestamps();
            $table->index(['tenant_id', 'status_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('pos_terminals');
    }
};