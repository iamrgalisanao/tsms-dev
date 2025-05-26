<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            // Add a nullable JSON column to store extra response details like headers, timings, etc.
            $table->json('response_metadata')->nullable()->after('response_payload');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            $table->dropColumn('response_metadata');
        });
    }
};

