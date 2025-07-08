<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transaction_validations', function (Blueprint $table) {
            $table->id();
            $table->char('transaction_id', 36);
            $table->string('status_code', 20);
            $table->text('validation_details')->nullable();
            $table->string('error_code', 191)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreign('transaction_id')->references('transaction_id')->on('transactions')->onDelete('cascade');
            $table->foreign('status_code')->references('code')->on('validation_statuses')->onUpdate('cascade');
        });
    }
    public function down(): void {
        Schema::dropIfExists('transaction_validations');
    }
};