<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ingestion_quarantine')) {
            return;
        }

        Schema::create('ingestion_quarantine', function (Blueprint $table) {
            $table->id();
            $table->uuid('submission_uuid')->nullable()->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('terminal_id')->nullable()->index();
            $table->longText('payload');
            $table->string('payload_checksum_received', 128)->nullable();
            $table->string('payload_checksum_computed', 128)->nullable();
            $table->string('status', 32)->default('NEW')->index();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('replayed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'terminal_id', 'created_at'], 'ing_quarantine_tenant_terminal_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_quarantine');
    }
};
