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
        Schema::create('security_alert_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->foreignId('security_alert_rule_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->enum('status', ['acknowledged', 'investigating', 'resolved', 'false_positive'])
                  ->default('acknowledged');
            $table->foreignId('acknowledged_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->foreignId('resolved_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('response_notes')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
            
            // Indexes for efficient querying
            $table->index(['tenant_id', 'status']);
            $table->index(['security_alert_rule_id']);
            $table->index(['acknowledged_at']);
            $table->index(['resolved_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_alert_responses');
    }
};
