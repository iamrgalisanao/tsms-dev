<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdatePayloadChecksumTypeInTransactionsTable extends Migration
{
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Update payload_checksum to CHAR(64)
            $table->char('payload_checksum', 64)->change();
        });
    }

    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Revert to VARCHAR(64) if needed
            $table->string('payload_checksum', 64)->change();
        });
    }
}
