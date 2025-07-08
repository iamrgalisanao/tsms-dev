<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
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
            $table->foreignId('tenant_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->string('name', 100);
            $table->string('status', 20)->default('CLOSED');
            $table->unsignedInteger('trip_count')->default(0);
            $table->unsignedInteger('failure_threshold')->default(5);
            $table->unsignedInteger('reset_timeout')->default(60);
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();

            // Unique constraint: one circuit breaker name per tenant
            $table->unique(['tenant_id', 'name'], 'ux_circuit_tenant_name');

            // Foreign key to statuses lookup table
            $table->foreign('status')
                  ->references('code')
                  ->on('breaker_statuses')
                  ->onUpdate('cascade');
        });

        // Add CHECK constraints for positive values
        DB::statement(
            'ALTER TABLE circuit_breakers
             ADD CONSTRAINT chk_failure_threshold_positive CHECK (failure_threshold > 0),
             ADD CONSTRAINT chk_reset_timeout_positive CHECK (reset_timeout > 0)'
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('circuit_breakers', function (Blueprint $table) {
            $table->dropForeign(['status']);
            $table->dropUnique('ux_circuit_tenant_name');
        });

        Schema::dropIfExists('circuit_breakers');
    }
};
