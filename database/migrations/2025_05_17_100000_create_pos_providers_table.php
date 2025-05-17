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
        // First, create the pos_providers table
        Schema::create('pos_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('api_key')->nullable();
            $table->text('description')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        
        // Add columns to pos_terminals table
        if (Schema::hasTable('pos_terminals')) {
            // Check if the columns already exist before adding them
            if (!Schema::hasColumn('pos_terminals', 'provider_id')) {
                Schema::table('pos_terminals', function (Blueprint $table) {
                    $table->foreignId('provider_id')->nullable()->after('tenant_id');
                });
            }
            
            if (!Schema::hasColumn('pos_terminals', 'enrolled_at')) {
                Schema::table('pos_terminals', function (Blueprint $table) {
                    $table->timestamp('enrolled_at')->nullable()->after('registered_at');
                });
            }
            
            // Add foreign key constraint - use raw query to check for existing constraint
            if (!$this->hasConstraint('pos_terminals', 'pos_terminals_provider_id_foreign')) {
                Schema::table('pos_terminals', function (Blueprint $table) {
                    $table->foreign('provider_id')->references('id')->on('pos_providers')->nullOnDelete();
                });
            }
        }
        
        // Finally create the provider_statistics table
        Schema::create('provider_statistics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('provider_id');
            $table->date('date');
            $table->integer('terminal_count')->default(0);
            $table->integer('active_terminal_count')->default(0);
            $table->integer('inactive_terminal_count')->default(0);
            $table->integer('new_enrollments')->default(0);
            $table->timestamps();
            
            $table->unique(['provider_id', 'date']);
            $table->foreign('provider_id')->references('id')->on('pos_providers')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('provider_statistics');
        
        // Remove the foreign key constraint if it exists
        if (Schema::hasTable('pos_terminals') && $this->hasConstraint('pos_terminals', 'pos_terminals_provider_id_foreign')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                $table->dropForeign(['provider_id']);
            });
        }
        
        // Drop columns if they exist
        if (Schema::hasTable('pos_terminals')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                if (Schema::hasColumn('pos_terminals', 'provider_id')) {
                    $table->dropColumn('provider_id');
                }
                
                if (Schema::hasColumn('pos_terminals', 'enrolled_at')) {
                    $table->dropColumn('enrolled_at');
                }
            });
        }
        
        Schema::dropIfExists('pos_providers');
    }
    
    /**
     * Check if a constraint exists using raw SQL, compatible with Laravel 11
     * 
     * @param string $table The table name
     * @param string $constraintName The constraint name
     * @return bool Whether the constraint exists
     */
    private function hasConstraint($table, $constraintName)
    {
        try {
            $database = DB::connection()->getDatabaseName();
            
            // For MySQL
            if (DB::connection()->getDriverName() === 'mysql') {
                $constraints = DB::select("
                    SELECT CONSTRAINT_NAME 
                    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = ? 
                    AND CONSTRAINT_NAME = ?
                ", [$database, $table, $constraintName]);
                
                return count($constraints) > 0;
            }
            
            // Default behavior (less reliable)
            return false;
        } catch (\Exception $e) {
            // If we encounter an error, assume constraint doesn't exist to be safe
            return false;
        }
    }
};