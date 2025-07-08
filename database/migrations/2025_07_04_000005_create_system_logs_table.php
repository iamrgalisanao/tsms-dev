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
        Schema::create('system_logs', function (Blueprint $table) {
            // Primary key
            $table->bigIncrements('id');

            // Log classification
            $table->enum('type', ['payload_validation', 'integration', 'security', 'audit'])
                  ->comment('Category of the log event')
                  ->index();
            $table->enum('log_type', ['info', 'warning', 'error', 'debug'])
                  ->comment('High-level log severity type')
                  ->index();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])
                  ->comment('Business-level severity')
                  ->index();

            // Referenced identifiers
            $table->char('terminal_uid', 36)
                  ->comment('UUID of the POS terminal')
                  ->index();
            $table->char('transaction_id', 36)
                  ->nullable()
                  ->comment('UUID of the related transaction')
                  ->index();

            // Payload
            $table->string('message', 255)
                  ->nullable()
                  ->comment('Short human-readable message');
            $table->json('context')
                  ->nullable()
                  ->comment('Structured JSON for extra details');

            // Optional actor (for audit logs)
            $table->unsignedBigInteger('user_id')
                  ->nullable()
                  ->comment('FK to users.id for manual/audit events')
                  ->index();
            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('set null');

            // Timestamps
            $table->timestamp('created_at')
                  ->useCurrent()
                  ->comment('When the log entry was created');
            $table->timestamp('updated_at')
                  ->useCurrent()
                  ->useCurrentOnUpdate()
                  ->comment('When the log entry was last updated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::dropIfExists('system_logs');
    }
};