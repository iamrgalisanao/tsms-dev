<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->unsignedInteger('max_retries')->default(3)->after('webhook_url');
            $table->unsignedInteger('retry_interval_sec')->default(60)->after('max_retries');
            $table->boolean('retry_enabled')->default(true)->after('retry_interval_sec');
        });
    }

    public function down(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->dropColumn(['max_retries', 'retry_interval_sec', 'retry_enabled']);
        });
    }
};
