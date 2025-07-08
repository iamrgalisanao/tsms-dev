<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('validation_statuses', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('description')->nullable();
        });
    }
    public function down(): void {
        Schema::dropIfExists('validation_statuses');
    }
};