<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbac_audits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_type'); // RoleAttached, RoleDetached, PermissionAttached, PermissionDetached
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('target_type')->nullable(); // 'role' or 'permission'
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_name')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable(); // the user who performed the action
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_audits');
    }
};
