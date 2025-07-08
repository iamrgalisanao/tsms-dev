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
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->enum('event_type', [
                'login_failure',
                'suspicious_activity',
                'rate_limit_breach',
                'circuit_breaker_trip',
                'unauthorized_access',
                'permission_violation'
            ]);
            $table->enum('severity', ['info', 'warning', 'critical'])
                  ->default('info');
            $table->string('source_ip', 45)->nullable(); // IPv6 compatible
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();
            $table->json('context');
            $table->timestamp('event_timestamp');
            $table->timestamps();
            
            // Indexes for efficient querying
            $table->index(['tenant_id', 'event_type']);
            $table->index(['event_timestamp']);
            $table->index(['source_ip', 'event_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
