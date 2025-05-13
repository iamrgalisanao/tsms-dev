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
        Schema::table('security_events', function (Blueprint $table) {
            $table->string('event_type', 50)->change();
            $table->string('severity', 20)->change();
        });

        Schema::table('security_report_templates', function (Blueprint $table) {
            $table->string('format', 20)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->string('event_type', 20)->change();
            $table->string('severity', 10)->change();
        });

        Schema::table('security_report_templates', function (Blueprint $table) {
            $table->string('format', 10)->change();
        });
    }
};