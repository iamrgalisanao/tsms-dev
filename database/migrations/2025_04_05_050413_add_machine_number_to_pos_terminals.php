<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->string('machine_number')->nullable()->after('terminal_uid');
        });
    }

    public function down()
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->dropColumn('machine_number');
        });
    }
};
