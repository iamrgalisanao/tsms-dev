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
        // Update enum values using raw SQL
        DB::statement("ALTER TABLE integration_logs MODIFY status ENUM('SUCCESS','FAILED','RETRY','PERMANENTLY_FAILED') NOT NULL DEFAULT 'FAILED'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert enum values to previous state
        DB::statement("ALTER TABLE integration_logs MODIFY status ENUM('SUCCESS','FAILED','RETRY') NOT NULL DEFAULT 'FAILED'");
    }
};
