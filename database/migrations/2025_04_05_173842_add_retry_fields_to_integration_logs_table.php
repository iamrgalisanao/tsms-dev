<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            $table->tinyInteger('retry_count')->default(0)->after('http_status_code');
            $table->timestamp('next_retry_at')->nullable()->after('retry_count');
            $table->string('retry_reason')->nullable()->after('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            $table->dropColumn(['retry_count', 'next_retry_at', 'retry_reason']);
        });
    }
};
