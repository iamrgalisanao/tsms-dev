<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_refresh_states', function (Blueprint $table) {
            $table->unsignedInteger('server_id')->nullable()->after('refreshed_at');
            $table->string('database_name', 191)->nullable()->after('server_id');
        });
    }

    public function down(): void
    {
        Schema::table('report_refresh_states', function (Blueprint $table) {
            $table->dropColumn(['server_id', 'database_name']);
        });
    }
};
