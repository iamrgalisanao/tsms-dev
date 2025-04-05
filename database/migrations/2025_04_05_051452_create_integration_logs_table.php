<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('terminal_id')->constrained('pos_terminals')->onDelete('cascade');

            $table->string('transaction_id')->nullable();
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->enum('status', ['SUCCESS', 'FAILED', 'RETRY'])->default('FAILED');
            $table->string('error_message')->nullable();
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->ipAddress('source_ip')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('integration_logs');
    }
};
