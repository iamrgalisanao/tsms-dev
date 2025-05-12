<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('security_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->foreignId('security_report_template_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();
            $table->string('name');
            $table->enum('status', ['generating', 'completed', 'failed'])
                  ->default('generating');
            $table->json('filters')->nullable();
            $table->foreignId('generated_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('from_date')->nullable();
            $table->timestamp('to_date')->nullable();
            $table->json('results')->nullable();
            $table->string('export_path')->nullable();
            $table->enum('format', ['html', 'pdf', 'csv'])->default('html');
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            // Indexes for efficient querying
            $table->index(['tenant_id', 'status']);
            $table->index(['generated_by']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_reports');
    }
};
