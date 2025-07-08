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
        // Only create the table if it doesn't already exist
        if (!Schema::hasTable('provider_statistics')) {
            Schema::create('provider_statistics', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('provider_id');
                $table->date('date');
                $table->integer('terminal_count')->default(0);
                $table->integer('active_terminal_count')->default(0);
                $table->integer('inactive_terminal_count')->default(0);
                $table->integer('new_enrollments')->default(0);
                $table->integer('new_terminals_today')->default(0);
                $table->float('growth_rate')->default(0);
                $table->timestamps();
                
                $table->unique(['provider_id', 'date']);
                
                // Only add the foreign key if the pos_providers table exists
                if (Schema::hasTable('pos_providers')) {
                    $table->foreign('provider_id')
                          ->references('id')
                          ->on('pos_providers')
                          ->onDelete('cascade');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_statistics');
    }
};
