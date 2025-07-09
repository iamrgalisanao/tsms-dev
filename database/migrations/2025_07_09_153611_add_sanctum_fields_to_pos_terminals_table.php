<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            // Check if columns don't already exist
            if (!Schema::hasColumn('pos_terminals', 'api_key')) {
                $table->string('api_key')->nullable()->after('serial_number');
            }
            
            if (!Schema::hasColumn('pos_terminals', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('api_key');
            }
            
            // Add indexes for faster lookups (skip if they already exist)
            try {
                $table->index(['serial_number', 'is_active'], 'pos_terminals_serial_active_idx');
                $table->index(['api_key'], 'pos_terminals_api_key_idx');
            } catch (\Exception $e) {
                // Indexes might already exist, skip silently
            }
        });
    }

    public function down()
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            // Drop indexes
            try {
                $table->dropIndex('pos_terminals_serial_active_idx');
                $table->dropIndex('pos_terminals_api_key_idx');
            } catch (\Exception $e) {
                // Indexes might not exist, skip silently
            }
            
            // Drop columns if they exist
            if (Schema::hasColumn('pos_terminals', 'is_active')) {
                $table->dropColumn('is_active');
            }
            
            if (Schema::hasColumn('pos_terminals', 'api_key')) {
                $table->dropColumn('api_key');
            }
        });
    }
};