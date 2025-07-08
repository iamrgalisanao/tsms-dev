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
        Schema::create('security_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->string('name');
            $table->string('description')->nullable();
            $table->enum('event_type', [
                'login_failure',
                'suspicious_activity',
                'rate_limit_breach',
                'circuit_breaker_trip',
                'unauthorized_access',
                'permission_violation'
            ]);
            $table->unsignedInteger('threshold');
            $table->unsignedInteger('window_minutes');
            $table->enum('action', ['log', 'notify', 'block'])
                  ->default('log');
            $table->json('notification_channels')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Indexes for efficient querying
            $table->index(['tenant_id', 'event_type']);
            $table->index(['is_active']);
            
            // Ensure unique rule names per tenant
            $table->unique(['tenant_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_alert_rules');
    }
};