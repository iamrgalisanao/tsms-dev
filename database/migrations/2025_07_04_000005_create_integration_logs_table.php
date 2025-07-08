<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('terminal_id')->constrained('pos_terminals')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transaction_id')->nullable();
            $table->string('endpoint')->nullable();
            $table->string('log_type')->nullable();
            $table->string('severity')->nullable();
            $table->json('request_payload');
            $table->json('response_payload')->nullable();
            $table->json('response_metadata')->nullable();
            $table->json('validation_results')->nullable();
            $table->json('metadata')->nullable();
            $table->json('context')->nullable();
            $table->enum('status', ['SUCCESS','FAILED','RETRY','PENDING','PERMANENTLY_FAILED'])->default('PENDING');
            $table->string('error_message')->nullable();
            $table->string('message')->nullable();
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->ipAddress('source_ip')->nullable();
            $table->integer('retry_count')->nullable()->default(0);
            $table->unsignedTinyInteger('retry_attempts')->default(0);
            $table->unsignedTinyInteger('max_retries')->default(3);
            $table->boolean('retry_success')->default(false);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_retry_at')->nullable();
            $table->string('retry_reason')->nullable();
            $table->integer('response_time')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('token_issued_at')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->timestamps();
            $table->index(['log_type', 'severity']);
            $table->index(['status', 'retry_count']);
            $table->index('created_at');
            $table->index(['transaction_id', 'terminal_id']);
            $table->index('endpoint');
            $table->index('user_id');
        });
    }
    public function down(): void {
        Schema::dropIfExists('integration_logs');
    }
};
