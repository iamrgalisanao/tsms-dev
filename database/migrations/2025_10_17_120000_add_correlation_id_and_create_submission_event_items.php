<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add correlation_id to submission_events if not exists
        if (Schema::hasTable('submission_events') && !Schema::hasColumn('submission_events', 'correlation_id')) {
            Schema::table('submission_events', function (Blueprint $table) {
                $table->string('correlation_id', 191)->nullable()->after('occurred_at');
                $table->index('correlation_id', 'submission_events_correlation_idx');
            });
        }

        // Create submission_event_items table
        if (!Schema::hasTable('submission_event_items')) {
            Schema::create('submission_event_items', function (Blueprint $table) {
                $table->id();
                $table->uuid('submission_uuid');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('terminal_id');
                $table->string('transaction_id', 191);
                $table->string('status', 50);
                $table->string('reason_code', 100)->nullable();
                $table->json('reason_details')->nullable();
                $table->timestamp('occurred_at')->useCurrent();
                $table->string('correlation_id', 191)->nullable();
                $table->timestamps();

                $table->index(['submission_uuid'], 'sei_submission_idx');
                $table->index(['tenant_id', 'terminal_id', 'occurred_at'], 'sei_tenant_terminal_time_idx');
                $table->index(['transaction_id'], 'sei_txn_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('submission_event_items')) {
            Schema::dropIfExists('submission_event_items');
        }
        if (Schema::hasTable('submission_events') && Schema::hasColumn('submission_events', 'correlation_id')) {
            Schema::table('submission_events', function (Blueprint $table) {
                $table->dropIndex('submission_events_correlation_idx');
                $table->dropColumn('correlation_id');
            });
        }
    }
};
