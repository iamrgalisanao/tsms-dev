<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_logs')) {
            Schema::table('system_logs', function (Blueprint $table) {
                // Add log_type if it doesn't exist
                if (!Schema::hasColumn('system_logs', 'log_type')) {
                    $table->string('log_type')->after('type');
                }
            });
        } else {
            Schema::create('system_logs', function (Blueprint $table) {
                $table->id();
                $table->string('type');
                $table->string('log_type');
                $table->string('severity')->nullable();
                $table->foreignId('transaction_id')->nullable()->constrained()->onDelete('cascade');
                $table->string('terminal_uid')->nullable();
                $table->text('message');
                $table->json('context')->nullable();
                $table->timestamps();
                
                $table->index(['type', 'severity']);
                $table->index('log_type');
                $table->index('terminal_uid');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('system_logs', 'log_type')) {
            Schema::table('system_logs', function (Blueprint $table) {
                $table->dropColumn('log_type');
            });
        }
    }
};