<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('terminal_id')->constrained('pos_terminals')->onDelete('cascade');
            $table->uuid('transaction_id')->unique();
            $table->string('hardware_id');
            $table->dateTime('transaction_timestamp');
            $table->decimal('base_amount', 15, 2);
            $table->string('customer_code')->nullable();
            $table->string('payload_checksum', 64);
            $table->enum('validation_status', ['VALID', 'ERROR', 'PENDING'])->default('PENDING');
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('transactions');
    }
};
