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
                $table->foreignId('tenant_id')->constrained();
                $table->string('terminal_uid')->unique();
                $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
                $table->boolean('is_sandbox')->default(false);
                $table->string('webhook_url')->nullable();
                $table->integer('max_retries')->default(3);
                $table->integer('retry_interval_sec')->default(300);
                $table->boolean('retry_enabled')->default(true);
                $table->string('jwt_token');
                $table->timestamp('registered_at');
                $table->timestamps();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('pos_terminals');
    }
};