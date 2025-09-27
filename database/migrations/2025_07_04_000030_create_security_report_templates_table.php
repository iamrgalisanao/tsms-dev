<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('filters')->nullable();
            $table->enum('type', ['event_summary', 'alert_summary', 'user_activity', 'ip_activity', 'custom']);
            $table->json('columns')->nullable();
            $table->enum('format', ['html', 'pdf', 'csv'])->default('html');
            $table->boolean('is_scheduled')->default(false);
            $table->string('schedule_frequency')->nullable();
            $table->json('notification_settings')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['is_scheduled']);
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_report_templates');
    }
};
