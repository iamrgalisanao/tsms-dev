<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCircuitBreakersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('circuit_breakers', function (Blueprint $table) {
            $table->id();
            $table->string('service_name')->comment('Name of the service or endpoint being monitored');
            $table->enum('state', ['CLOSED', 'OPEN', 'HALF_OPEN'])->default('CLOSED')
                  ->comment('CLOSED=normal operation, OPEN=failing/not accepting requests, HALF_OPEN=testing if recovered');
            $table->unsignedInteger('failure_count')->default(0)->comment('Current consecutive failures');
            $table->unsignedInteger('failure_threshold')->default(5)->comment('Number of failures that trips the circuit');
            $table->unsignedInteger('reset_timeout')->default(300)->comment('Seconds until a tripped circuit moves to half-open');
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->onDelete('cascade');
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('opened_at')->nullable()->comment('When the circuit was last opened');
            $table->timestamp('cooldown_until')->nullable()->comment('Time until the circuit can try half-open state');
            $table->timestamps();
            
            // Unique constraint to ensure one circuit breaker per service per tenant
            $table->unique(['service_name', 'tenant_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('circuit_breakers');
    }
}