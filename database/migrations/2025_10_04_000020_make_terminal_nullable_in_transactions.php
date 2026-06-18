<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Make terminal_id nullable so validation logic can run in tests that
        // intentionally insert transactions with null terminal_id.
        // Note: this migration uses ->change() which requires doctrine/dbal to be
        // installed in some environments. If absent, you'll need to install it.
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('terminal_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('terminal_id')->nullable(false)->change();
        });
    }
};
