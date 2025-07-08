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
        Schema::create('pos_providers', function (Blueprint $table) {
            $table->id(); // BIGINT UNSIGNED, PK, auto-increment
            $table->string('name', 255)->notNull(); // VARCHAR(255) NOT NULL
            $table->timestamps(); // created_at and updated_at TIMESTAMP NOT NULL
            $table->softDeletes(); // deleted_at TIMESTAMP nullable
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_providers');
    }
};