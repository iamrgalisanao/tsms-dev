<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('transaction_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('transaction_id', 36);
            $table->string('job_status', 20);
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->foreign('transaction_id')->references('transaction_id')->on('transactions')->onDelete('cascade');
            $table->foreign('job_status')->references('code')->on('job_statuses')->onUpdate('cascade');
        });
    }
    public function down(): void {
        Schema::dropIfExists('transaction_jobs');
    }
};