<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('submission_uuid')->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('terminal_id')->nullable()->index();
            $table->string('status', 32)->index(); // RECEIVED | COMPLETED | REJECTED
            $table->string('reason_code', 64)->nullable()->index(); // e.g., CHECKSUM_MISMATCH, VALIDATION_FAILED
            $table->json('reason_details')->nullable();
            $table->integer('transaction_count')->default(0);
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'terminal_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_events');
    }
};
