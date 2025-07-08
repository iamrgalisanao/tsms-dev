<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('breaker_statuses', function (Blueprint $table) {
            $table->string('code', 20)->primary();
            $table->string('label', 50)->nullable(false);
        });

        // Seed default status records
        DB::table('breaker_statuses')->insert([
            ['code' => 'OPEN',      'label' => 'Open – allowing all calls'],
            ['code' => 'CLOSED',    'label' => 'Closed – blocking all calls'],
            ['code' => 'HALF_OPEN', 'label' => 'Half-open – trial calls allowed'],
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('breaker_statuses');
    }
};