<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->unsignedBigInteger('user_id')->nullable();
            // New columns for authentication and resource tracking
            $table->string('action_type')->nullable();
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            // Optionally keep old columns for model auditing
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }
    public function down(): void {
        Schema::dropIfExists('audit_logs');
    }
};