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
        // First, check the current column type
        $columnType = DB::connection()->getDoctrineColumn('integration_logs', 'status')->getType()->getName();
        
        // If it's an ENUM, we need to modify it to include 'PENDING'
        if ($columnType === 'string' && DB::connection()->getDoctrineColumn('integration_logs', 'status')->getLength() < 10) {
            // For MySQL, we can change the column to VARCHAR to accommodate all possible values
            Schema::table('integration_logs', function (Blueprint $table) {
                $table->string('status', 20)->change();
            });
        }
        
        // Alternatively, if it's an ENUM, we need to use a raw query to modify it
        if (DB::connection()->getDriverName() === 'mysql') {
            // Get the current ENUM values
            $result = DB::select("SHOW COLUMNS FROM integration_logs WHERE Field = 'status'");
            
            if (!empty($result) && strpos($result[0]->Type, 'enum') === 0) {
                // Extract current enum values
                preg_match("/^enum\((.*)\)$/", $result[0]->Type, $matches);
                $enumValues = $matches[1];
                
                // Check if 'PENDING' is already in the enum
                if (strpos($enumValues, "'PENDING'") === false) {
                    // Add 'PENDING' to the enum
                    $newEnumValues = $enumValues . ",'PENDING'";
                    DB::statement("ALTER TABLE integration_logs MODIFY COLUMN status ENUM($newEnumValues)");
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // If we changed from ENUM to VARCHAR, we'd revert that here
        // However, since this might cause data loss, we'll leave it as is
    }
};
