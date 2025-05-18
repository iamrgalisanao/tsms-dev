<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Update pos_terminals table
        Schema::table('pos_terminals', function (Blueprint $table) {
            // Drop existing foreign keys if they exist
            if ($this->hasConstraint('pos_terminals', 'pos_terminals_tenant_id_foreign')) {
                $table->dropForeign(['tenant_id']);
            }
            if ($this->hasConstraint('pos_terminals', 'pos_terminals_provider_id_foreign')) {
                $table->dropForeign(['provider_id']);
            }
            
            // Re-add foreign keys with proper constraints
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');
                
            $table->foreign('provider_id')
                ->references('id')
                ->on('pos_providers')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['provider_id']);
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