<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateCircuitBreakersTableForDashboard extends Migration
{
    public function up(): void
    {
        Schema::table('circuit_breakers', function (Blueprint $table) {
            // Rename state to status if it exists
            if (Schema::hasColumn('circuit_breakers', 'state')) {
                $table->renameColumn('state', 'status');
            }
            
            // Add or modify columns to match frontend expectations
            if (!Schema::hasColumn('circuit_breakers', 'status')) {
                $table->enum('status', ['OPEN', 'CLOSED', 'HALF_OPEN'])->default('CLOSED');
            }
            if (!Schema::hasColumn('circuit_breakers', 'trip_count')) {
                $table->integer('trip_count')->default(0);
            }
            if (!Schema::hasColumn('circuit_breakers', 'name')) {
                $table->string('name')->after('tenant_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('circuit_breakers', function (Blueprint $table) {
            if (Schema::hasColumn('circuit_breakers', 'status')) {
                $table->renameColumn('status', 'state');
            }
        });
    }
}
