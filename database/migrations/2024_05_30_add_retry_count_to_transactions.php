<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('transactions', 'retry_count')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->integer('retry_count')->default(0);
            });
        }
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('retry_count');
        });
    }
};