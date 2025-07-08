<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transaction_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('terminal_id')->constrained('pos_terminals')->onDelete('cascade');
            $table->char('submission_uuid', 36)->unique();
            $table->dateTime('submission_timestamp')->nullable();
            $table->unsignedInteger('transaction_count')->default(1);
            $table->char('payload_checksum', 64);
            $table->string('status', 20)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('transaction_submissions');
    }
};