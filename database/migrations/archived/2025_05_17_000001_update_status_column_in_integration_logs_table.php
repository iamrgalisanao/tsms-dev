<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if the table exists
        if (Schema::hasTable('integration_logs')) {
            // Get the column info using raw query instead of getDoctrineColumn
            $columnInfo = DB::select("SHOW COLUMNS FROM integration_logs WHERE Field = 'status'");
            
            if (!empty($columnInfo)) {
                $columnType = $columnInfo[0]->Type;
                
                // Check if it's an ENUM type
                if (strpos($columnType, 'enum') === 0) {
                    // Extract current enum values
                    preg_match("/^enum\((.*)\)$/", $columnType, $matches);
                    $enumValues = $matches[1] ?? '';
                    
                    // Check if 'PENDING' is already in the enum
                    if (strpos($enumValues, "'PENDING'") === false) {
                        // Add 'PENDING' to the enum
                        $newEnumValues = $enumValues . ",'PENDING'";
                        DB::statement("ALTER TABLE integration_logs MODIFY COLUMN status ENUM($newEnumValues)");
                    }
                } else {
                    // If it's not an ENUM, simply modify it to be a string with enough length
                    Schema::table('integration_logs', function (Blueprint $table) {
                        $table->string('status', 20)->change();
                    });
                }
            } else {
                // If the column doesn't exist, add it
                Schema::table('integration_logs', function (Blueprint $table) {
                    if (!Schema::hasColumn('integration_logs', 'status')) {
                        $table->string('status', 20)->default('PENDING');
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Since we're just modifying a column to be more permissive,
        // there's no need for a rollback operation that might cause data loss
    }
};