<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // First convert existing values to match new ENUM values
        DB::table('transactions')
            ->where('promo_status', 'WITH_APPROVAL')
            ->update(['promo_status' => 'WITH_APPROVAL']);
            
        // Modify the column to ENUM
        DB::statement("ALTER TABLE transactions MODIFY COLUMN promo_status ENUM('WITH_APPROVAL', 'WITHOUT_APPROVAL') DEFAULT NULL");
    }

    public function down()
    {
        DB::statement("ALTER TABLE transactions MODIFY COLUMN promo_status VARCHAR(255)");
    }
};
