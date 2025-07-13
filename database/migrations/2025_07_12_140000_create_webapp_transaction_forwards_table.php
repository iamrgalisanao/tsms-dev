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
        Schema::create('webapp_transaction_forwards', function (Blueprint $table) {
            $table->id();
            
            // Core relationship to transactions
            $table->foreignId('transaction_id')
                  ->constrained('transactions')
                  ->onDelete('cascade');
            
            // Forwarding metadata
            $table->string('batch_id')->nullable(); // For grouping bulk forwards
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])
                  ->default('pending');
            
            // Attempt tracking
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->timestamp('first_attempted_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            // Response tracking
            $table->json('request_payload')->nullable();
            $table->json('response_data')->nullable();
            $table->integer('response_status_code')->nullable();
            $table->text('error_message')->nullable();
            
            // Retry scheduling
            $table->timestamp('next_retry_at')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['transaction_id']);
            $table->index(['status', 'next_retry_at']);
            $table->index(['batch_id']);
            $table->index(['completed_at']);
            $table->index(['created_at']);
            
            // Ensure one forwarding record per transaction
            $table->unique(['transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webapp_transaction_forwards');
    }
};
