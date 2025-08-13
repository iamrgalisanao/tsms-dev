<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'job_status')) {
                $table->string('job_status', 20)->nullable()->default('QUEUED')->after('validation_status');
                $table->index('job_status', 'idx_transactions_job_status');
            }
            if (!Schema::hasColumn('transactions', 'last_error')) {
                $table->text('last_error')->nullable()->after('job_status');
            }
            if (!Schema::hasColumn('transactions', 'job_attempts')) {
                $table->unsignedInteger('job_attempts')->default(0)->after('last_error');
            }
            if (!Schema::hasColumn('transactions', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('job_attempts');
            }
        });
    }

    public function down(): void {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
            if (Schema::hasColumn('transactions', 'job_attempts')) {
                $table->dropColumn('job_attempts');
            }
            if (Schema::hasColumn('transactions', 'last_error')) {
                $table->dropColumn('last_error');
            }
            if (Schema::hasColumn('transactions', 'job_status')) {
                try { $table->dropIndex('idx_transactions_job_status'); } catch (\Throwable $e) {}
                $table->dropColumn('job_status');
            }
        });
    }
};
