<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('pos_terminals')) {
            Schema::create('pos_terminals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('provider_id');
                $table->string('terminal_uid');
                $table->timestamp('registered_at')->nullable();
                $table->timestamp('enrolled_at')->nullable();
                $table->string('status');
                $table->boolean('is_sandbox')->default(false);
                $table->string('webhook_url')->nullable();
                $table->integer('max_retries')->default(3);
                $table->integer('retry_interval_sec')->default(300);
                $table->boolean('retry_enabled')->default(true);
                $table->string('jwt_token')->nullable();
                $table->timestamps();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_revoked')->default(false);
            });

            // Add foreign key constraints
            Schema::table('pos_terminals', function (Blueprint $table) {
                $table->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->onDelete('cascade');
                
                $table->foreign('provider_id')
                    ->references('id')
                    ->on('pos_providers')
                    ->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('pos_terminals');
    }
};