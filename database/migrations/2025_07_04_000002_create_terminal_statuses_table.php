<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        Schema::create('terminal_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
        });

        // Seed default statuses
        DB::table('terminal_statuses')->insert([
            ['name' => 'active'],
            ['name' => 'in_active'],
            ['name' => 'revoked'],
            ['name' => 'expired'],
        ]);
    }

    public function down(): void {
        Schema::dropIfExists('terminal_statuses');
    }
};
