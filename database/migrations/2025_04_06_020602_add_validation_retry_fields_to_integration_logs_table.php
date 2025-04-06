<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            $table->enum('validation_status', ['PASSED', 'FAILED'])->nullable()->after('status');
            $table->integer('response_time')->nullable()->after('http_status_code'); // in milliseconds
            $table->unsignedTinyInteger('retry_attempts')->default(0)->after('retry_count');
        });
    }

    public function down(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            $table->dropColumn(['validation_status', 'response_time', 'retry_attempts']);
        });
    }
};

