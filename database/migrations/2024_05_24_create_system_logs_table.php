<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('severity');
            $table->string('terminal_uid')->nullable();
            $table->string('transaction_id')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();
            
            $table->index(['type', 'severity']);
            $table->index('terminal_uid');
            $table->index('transaction_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};