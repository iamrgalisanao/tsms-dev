<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('webhook_logs')) {
            Schema::create('webhook_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('terminal_id')->nullable()->constrained('pos_terminals')->nullOnDelete();
                $table->string('transaction_id')->nullable();
                $table->string('endpoint');
                $table->enum('status', ['SUCCESS', 'FAILED', 'PENDING'])->default('PENDING');
                $table->integer('http_code')->nullable();
                $table->json('request_payload');
                $table->json('response_payload')->nullable();
                $table->json('response_body')->nullable();
                $table->string('error_message')->nullable();
                $table->unsignedTinyInteger('retry_count')->default(0);
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('next_retry_at')->nullable();
                $table->unsignedTinyInteger('max_retries')->default(3);
                $table->integer('response_time')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                // Indexes
                $table->index(['status', 'created_at']);
                $table->index('terminal_id');
                $table->index('endpoint');
                $table->index('transaction_id');
            });
        } else {
            Schema::table('webhook_logs', function (Blueprint $table) {
                // Add new columns if they don't exist
                if (!Schema::hasColumn('webhook_logs', 'transaction_id')) {
                    $table->string('transaction_id')->nullable()->after('terminal_id');
                }
                if (!Schema::hasColumn('webhook_logs', 'http_code')) {
                    $table->integer('http_code')->nullable()->after('status');
                }
                if (!Schema::hasColumn('webhook_logs', 'response_body')) {
                    $table->json('response_body')->nullable()->after('response_payload');
                }
                if (!Schema::hasColumn('webhook_logs', 'sent_at')) {
                    $table->timestamp('sent_at')->nullable()->after('response_time');
                }
                
                // Add retry fields if they don't exist
                if (!Schema::hasColumn('webhook_logs', 'retry_count')) {
                    $table->unsignedTinyInteger('retry_count')->default(0)->after('error_message');
                }
                if (!Schema::hasColumn('webhook_logs', 'last_attempt_at')) {
                    $table->timestamp('last_attempt_at')->nullable()->after('retry_count');
                }
                if (!Schema::hasColumn('webhook_logs', 'next_retry_at')) {
                    $table->timestamp('next_retry_at')->nullable()->after('last_attempt_at');
                }
                if (!Schema::hasColumn('webhook_logs', 'max_retries')) {
                    $table->unsignedTinyInteger('max_retries')->default(3)->after('next_retry_at');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};