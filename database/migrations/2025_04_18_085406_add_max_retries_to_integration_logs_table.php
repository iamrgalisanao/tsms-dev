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
    Schema::table('integration_logs', function (Blueprint $table) {
        $table->tinyInteger('max_retries')->default(3)->after('retry_attempts');
    });
}

public function down(): void
{
    Schema::table('integration_logs', function (Blueprint $table) {
        $table->dropColumn('max_retries');
    });
}

};
