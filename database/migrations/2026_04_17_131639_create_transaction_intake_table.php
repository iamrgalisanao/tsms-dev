<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transaction_intake', function (Blueprint $table) {
            $table->id();
            $table->uuid('submission_uuid')->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('terminal_id');
            $table->string('payload_checksum');
            $table->json('payload');
            $table->unsignedInteger('payload_size_bytes');
            $table->string('source_ip')->nullable();
            
            // Intake Status: RECEIVED, REJECTED, ACCEPTED, QUEUED
            $table->string('intake_status')->default('RECEIVED');
            
            // Processing Status: PROCESSING, PROCESSED, DUPLICATE, FAILED_RETRYABLE, FAILED_PERMANENT, DEAD_LETTERED
            $table->string('processing_status')->nullable();
            
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedBigInteger('duplicate_of_intake_id')->nullable();
            $table->uuid('trace_id')->index();
            
            $table->timestamp('received_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            
            $table->timestamps();

            // Indexes for operational analysis
            $table->index('tenant_id');
            $table->index('terminal_id');
            $table->index('received_at');
            $table->index('processing_status');
            $table->index(['tenant_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_intake');
    }
};
