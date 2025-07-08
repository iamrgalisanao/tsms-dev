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
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('submission_uuid')->nullable()->after('validation_status');
            $table->timestamp('submission_timestamp')->nullable()->after('submission_uuid');
            
            // Add index for better performance on submission queries
            $table->index('submission_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['submission_uuid']);
            $table->dropColumn(['submission_uuid', 'submission_timestamp']);
        });
    }
};