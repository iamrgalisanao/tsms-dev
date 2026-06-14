<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_logs')) {
            return;
        }

        DB::statement('ALTER TABLE system_logs MODIFY COLUMN type VARCHAR(64) NOT NULL');
        DB::statement('ALTER TABLE system_logs MODIFY COLUMN log_type VARCHAR(64) NOT NULL');
        DB::statement('ALTER TABLE system_logs MODIFY COLUMN severity VARCHAR(32) NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_logs')) {
            return;
        }

        DB::statement("ALTER TABLE system_logs MODIFY COLUMN type ENUM('payload_validation', 'integration', 'security', 'audit') NOT NULL");
        DB::statement("ALTER TABLE system_logs MODIFY COLUMN log_type ENUM('info', 'warning', 'error', 'debug') NOT NULL");
        DB::statement("ALTER TABLE system_logs MODIFY COLUMN severity ENUM('low', 'medium', 'high', 'critical') NOT NULL");
    }
};
