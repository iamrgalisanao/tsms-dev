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
        Schema::create('provider_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('pos_providers')->cascadeOnDelete();
            $table->date('date');
            $table->integer('terminal_count')->default(0);
            $table->integer('active_terminal_count')->default(0);
            $table->integer('new_terminals_today')->default(0);
            $table->float('growth_rate')->default(0);
            $table->timestamps();
            
            // Composite unique key
            $table->unique(['provider_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_statistics');
    }
};
