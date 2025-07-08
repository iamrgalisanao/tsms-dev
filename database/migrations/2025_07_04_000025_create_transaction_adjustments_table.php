<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transaction_adjustments', function (Blueprint $table) {
            $table->id();
            $table->char('transaction_id', 36);
            $table->string('adjustment_type', 50);
            $table->decimal('amount', 15, 2);
            $table->timestamps(); // Creates both created_at and updated_at
            $table->foreign('transaction_id')->references('transaction_id')->on('transactions')->onDelete('cascade');
        });
    }
    public function down(): void {
        Schema::dropIfExists('transaction_adjustments');
    }
};