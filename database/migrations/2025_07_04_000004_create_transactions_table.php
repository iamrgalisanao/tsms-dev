<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants');
            $table->foreignId('terminal_id')->constrained('pos_terminals');
            $table->string('transaction_id')->unique();
            $table->string('hardware_id');
            $table->timestamp('transaction_timestamp');
            $table->decimal('base_amount', 12, 2);
            $table->string('customer_code');
            $table->string('payload_checksum');
            $table->enum('validation_status', ['PENDING', 'VALID', 'INVALID'])->default('PENDING');
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('transactions');
    }
};