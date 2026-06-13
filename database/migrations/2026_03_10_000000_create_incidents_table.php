<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->uuid('submission_uuid')->nullable()->index();
            $table->uuid('correlation_id')->nullable()->index();

            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('terminal_id')->nullable()->index();

            $table->string('category', 64)->nullable()->index(); // e.g. PAYLOAD_VALIDATION, CHECKSUM, DUPLICATE
            $table->string('state', 32)->default('OPEN')->index(); // OPEN, ACKED, RESOLVED, IGNORED

            $table->string('reason_code', 128)->nullable();
            $table->string('human_title', 255)->nullable();
            $table->text('human_message')->nullable();
            $table->text('pos_action')->nullable();

            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('occurrence_count')->default(0);

            $table->json('context')->nullable();

            $table->string('assigned_to', 191)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
