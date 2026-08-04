<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 80)->index();
            $table->string('reason_code', 80)->nullable()->index();
            $table->string('severity', 30)->default('info')->index();
            $table->string('license_id')->nullable()->index();
            $table->string('client_id')->nullable()->index();
            $table->string('deployment_id')->nullable()->index();
            $table->string('location_code')->nullable()->index();
            $table->foreignId('tenant_id')->nullable()->index();
            $table->foreignId('terminal_id')->nullable()->index();
            $table->string('module_code')->nullable()->index();
            $table->string('current_fingerprint_hash', 128)->nullable();
            $table->string('expected_fingerprint_hash', 128)->nullable();
            $table->string('request_method', 20)->nullable();
            $table->string('request_path')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->foreignId('user_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['deployment_id', 'created_at']);
            $table->index(['reason_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_audit_logs');
    }
};
