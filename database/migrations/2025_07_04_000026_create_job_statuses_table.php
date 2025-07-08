<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('job_statuses', function (Blueprint $table) {
            $table->string('code', 20)->primary();
            $table->string('description', 100)->nullable();
        });
    }
    public function down(): void {
        Schema::dropIfExists('job_statuses');
    }
};
