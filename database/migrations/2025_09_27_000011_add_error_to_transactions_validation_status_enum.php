<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Make sure column exists before attempting to modify
        try {
            DB::statement("ALTER TABLE `transactions` MODIFY COLUMN `validation_status` ENUM('PENDING','VALID','ERROR','INVALID') NOT NULL DEFAULT 'PENDING'");
        } catch (\Exception $e) {
            // ignore if cannot alter (older DBs or different schema)
        }
    }

    public function down()
    {
        try {
            DB::statement("ALTER TABLE `transactions` MODIFY COLUMN `validation_status` ENUM('PENDING','VALID','INVALID') NOT NULL DEFAULT 'PENDING'");
        } catch (\Exception $e) {
            // ignore
        }
    }
};
