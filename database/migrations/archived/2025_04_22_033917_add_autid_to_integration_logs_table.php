<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            $table->string('ip_address')->nullable()->after('terminal_id');
            $table->timestamp('token_issued_at')->nullable()->after('ip_address');
            $table->timestamp('token_expires_at')->nullable()->after('token_issued_at');
            $table->integer('latency_ms')->nullable()->after('token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'token_issued_at', 'token_expires_at', 'latency_ms']);
        });
    }
};
