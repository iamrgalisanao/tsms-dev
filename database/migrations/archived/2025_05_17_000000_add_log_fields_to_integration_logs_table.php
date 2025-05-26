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
            // Check if columns don't exist before adding them
            if (!Schema::hasColumn('integration_logs', 'log_type')) {
                $table->string('log_type', 50)->nullable()->after('retry_attempts')->index();
            }
            
            if (!Schema::hasColumn('integration_logs', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('log_type');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            }
            
            if (!Schema::hasColumn('integration_logs', 'severity')) {
                $table->string('severity', 20)->nullable()->after('user_id')->index();
            }
            
            if (!Schema::hasColumn('integration_logs', 'message')) {
                $table->text('message')->nullable()->after('severity');
            }
            
            if (!Schema::hasColumn('integration_logs', 'context')) {
                $table->json('context')->nullable()->after('message');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integration_logs', function (Blueprint $table) {
            // Remove columns in reverse order
            if (Schema::hasColumn('integration_logs', 'context')) {
                $table->dropColumn('context');
            }
            
            if (Schema::hasColumn('integration_logs', 'message')) {
                $table->dropColumn('message');
            }
            
            if (Schema::hasColumn('integration_logs', 'severity')) {
                $table->dropColumn('severity');
            }
            
            if (Schema::hasColumn('integration_logs', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }
            
            if (Schema::hasColumn('integration_logs', 'log_type')) {
                $table->dropColumn('log_type');
            }
        });
    }
};