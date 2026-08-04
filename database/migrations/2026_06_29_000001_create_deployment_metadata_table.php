<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_metadata', function (Blueprint $table) {
            $table->id();
            $table->string('deployment_id')->index();
            $table->string('license_id')->nullable()->index();
            $table->string('client_id')->nullable()->index();
            $table->string('environment')->index();
            $table->uuid('application_installation_uuid')->unique();
            $table->uuid('database_instance_uuid')->unique();
            $table->string('current_fingerprint_hash', 128)->nullable();
            $table->timestamp('first_activated_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->string('status', 50)->default('pending')->index();
            $table->timestamps();

            $table->unique(['deployment_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_metadata');
    }
};
