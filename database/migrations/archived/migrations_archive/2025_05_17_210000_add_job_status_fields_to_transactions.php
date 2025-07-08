<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Add new job tracking columns to existing table
            $table->string('job_status')->nullable()->default('QUEUED');
            $table->text('last_error')->nullable();
            $table->unsignedInteger('job_attempts')->default(0);
            $table->timestamp('completed_at')->nullable();
            
            // Add index for job status queries
            $table->index('job_status');
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['job_status', 'last_error', 'job_attempts', 'completed_at']);
            $table->dropIndex(['job_status']);
        });
    }
};