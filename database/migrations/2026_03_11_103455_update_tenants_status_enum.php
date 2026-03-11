<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Using DB::statement for enum updates
            \DB::statement("ALTER TABLE tenants MODIFY COLUMN status ENUM('Operational', 'Not Operational', 'Closed', 'Pending') DEFAULT 'Operational'");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            \DB::statement("ALTER TABLE tenants MODIFY COLUMN status ENUM('Operational', 'Not Operational') DEFAULT 'Operational'");
        });
    }
};
