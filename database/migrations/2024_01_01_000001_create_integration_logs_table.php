<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('integration_logs')) {
            Schema::create('integration_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
                $table->foreignId('terminal_id')->constrained('pos_terminals')->onDelete('cascade');
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('transaction_id')->nullable();
                $table->string('endpoint')->nullable();
                $table->string('log_type')->nullable();
                $table->string('severity')->nullable();
                $table->json('request_payload');
                $table->json('response_payload')->nullable();
                $table->json('response_metadata')->nullable();
                $table->json('validation_results')->nullable();
                $table->json('metadata')->nullable();
                $table->json('context')->nullable();
                $table->enum('status', [
                    'SUCCESS',
                    'FAILED',
                    'RETRY',
                    'PENDING',
                    'PERMANENTLY_FAILED'
                ])->default('PENDING');
                $table->string('error_message')->nullable();
                $table->string('message')->nullable();
                $table->unsignedSmallInteger('http_status_code')->nullable();
                $table->ipAddress('source_ip')->nullable();
                $table->integer('retry_count')->nullable()->default(0);
                $table->unsignedTinyInteger('retry_attempts')->default(0);
                $table->unsignedTinyInteger('max_retries')->default(3);
                $table->boolean('retry_success')->default(false);
                $table->timestamp('next_retry_at')->nullable();
                $table->timestamp('last_retry_at')->nullable();
                $table->string('retry_reason')->nullable();
                $table->integer('response_time')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->string('ip_address')->nullable();
                $table->timestamp('token_issued_at')->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->integer('latency_ms')->nullable();
                $table->timestamps();

                // Indexes
                $table->index(['log_type', 'severity']);
                $table->index(['status', 'retry_count']);
                $table->index('created_at');
                $table->index(['transaction_id', 'terminal_id']);
                $table->index('endpoint');
                $table->index('user_id');
            });
        } else {
            // Update existing table with new columns
            Schema::table('integration_logs', function (Blueprint $table) {
                // Add columns if they don't exist
                if (!Schema::hasColumn('integration_logs', 'user_id')) {
                    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                }
                if (!Schema::hasColumn('integration_logs', 'endpoint')) {
                    $table->string('endpoint')->nullable();
                }
                if (!Schema::hasColumn('integration_logs', 'metadata')) {
                    $table->json('metadata')->nullable();
                }
                if (!Schema::hasColumn('integration_logs', 'context')) {
                    $table->json('context')->nullable();
                }
                if (!Schema::hasColumn('integration_logs', 'message')) {
                    $table->string('message')->nullable();
                }
                if (!Schema::hasColumn('integration_logs', 'retry_success')) {
                    $table->boolean('retry_success')->default(false);
                }
                if (!Schema::hasColumn('integration_logs', 'last_retry_at')) {
                    $table->timestamp('last_retry_at')->nullable();
                }
                if (!Schema::hasColumn('integration_logs', 'processed_at')) {
                    $table->timestamp('processed_at')->nullable();
                }

                // Safely add indexes
                $this->safelyAddIndex('integration_logs', ['log_type', 'severity']);
                $this->safelyAddIndex('integration_logs', ['status', 'retry_count']);
            });
        }
    }

    public function down(): void {
        // Don't drop the table, just remove the new columns if necessary
        Schema::table('integration_logs', function (Blueprint $table) {
            // Only drop columns that we added
            $newColumns = [
                'user_id', 'endpoint', 'metadata', 'context', 'message',
                'retry_success', 'last_retry_at', 'processed_at'
            ];
            
            foreach ($newColumns as $column) {
                if (Schema::hasColumn('integration_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasIndex($table, $columns)
    {
        // Use raw SQL to check for index existence
        $indexName = implode('_', array_merge([$table], $columns)) . '_index';
        $indexes = DB::select(
            "SHOW INDEXES FROM {$table} WHERE Key_name = ?",
            [$indexName]
        );
        
        return count($indexes) > 0;
    }

    private function safelyAddIndex($table, $columns)
    {
        try {
            Schema::table($table, function (Blueprint $table) use ($columns) {
                $table->index($columns);
            });
        } catch (\Exception $e) {
            // Index might already exist, ignore the error
            return false;
        }
        return true;
    }
};