<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('retry_count')->default(0)->after('error_message');
            $table->timestamp('last_attempt_at')->nullable()->after('retry_count');
            $table->timestamp('next_retry_at')->nullable()->after('last_attempt_at');
            $table->unsignedTinyInteger('max_retries')->default(3)->after('next_retry_at');
        });
    }

    public function down(): void {
        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->dropColumn([
                'retry_count',
                'last_attempt_at',
                'next_retry_at',
                'max_retries',
            ]);
        });
    }
};



