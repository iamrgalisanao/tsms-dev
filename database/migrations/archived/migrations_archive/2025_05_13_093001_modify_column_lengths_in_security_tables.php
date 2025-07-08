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
        // Modify type column in security_report_templates
        Schema::table('security_report_templates', function (Blueprint $table) {
            $table->string('type', 100)->change();  // Increase length to 100 chars
        });

        // Modify format column in security_reports
        Schema::table('security_reports', function (Blueprint $table) {
            $table->string('format', 50)->change();  // Increase length to 50 chars
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert type column in security_report_templates
        Schema::table('security_report_templates', function (Blueprint $table) {
            $table->string('type', 50)->change();
        });

        // Revert format column in security_reports
        Schema::table('security_reports', function (Blueprint $table) {
            $table->string('format', 20)->change();
        });
    }
};
