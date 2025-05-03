<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('circuit_breakers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->enum('status', ['OPEN', 'CLOSED', 'HALF_OPEN'])->default('CLOSED');
            $table->integer('trip_count')->default(0);
            $table->integer('failure_threshold')->default(5);
            $table->integer('reset_timeout')->default(60); // in seconds
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamps();
            
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuit_breakers');
    }
};
