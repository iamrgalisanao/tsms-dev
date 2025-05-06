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
    Schema::table('circuit_breakers', function (Blueprint $table) {
        $table->integer('failures')->default(0)->after('status');
    });
}

public function down(): void
{
    Schema::table('circuit_breakers', function (Blueprint $table) {
        $table->dropColumn('failures');
    });
}
};
