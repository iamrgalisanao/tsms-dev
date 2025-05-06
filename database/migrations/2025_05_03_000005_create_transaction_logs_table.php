<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terminal_id')->constrained('pos_terminals');
            $table->string('transaction_type');
            $table->decimal('amount', 10, 2);
            $table->string('status');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Add indexes for better query performance
            $table->index(['terminal_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_logs');
    }
};