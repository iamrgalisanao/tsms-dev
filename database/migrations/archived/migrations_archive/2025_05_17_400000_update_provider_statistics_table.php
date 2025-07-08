<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::table('provider_statistics', function (Blueprint $table) {
            // Drop existing foreign key if it exists
            if ($this->hasConstraint('provider_statistics', 'provider_statistics_provider_id_foreign')) {
                $table->dropForeign(['provider_id']);
            }

            // Re-add foreign key with proper constraint
            $table->foreign('provider_id')
                ->references('id')
                ->on('pos_providers')
                ->onDelete('cascade');

            // Add unique constraint if it doesn't exist
            if (!$this->hasConstraint('provider_statistics', 'provider_statistics_provider_id_date_unique')) {
                $table->unique(['provider_id', 'date'], 'provider_statistics_provider_id_date_unique');
            }
        });
    }

    public function down()
    {
        Schema::table('provider_statistics', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropUnique('provider_statistics_provider_id_date_unique');
        });
    }

    private function hasConstraint($table, $constraintName)
    {
        $database = DB::connection()->getDatabaseName();
        $constraints = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = ? 
            AND TABLE_NAME = ? 
            AND CONSTRAINT_NAME = ?
        ", [$database, $table, $constraintName]);
        
        return count($constraints) > 0;
    }
};
