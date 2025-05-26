<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateStoresTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Check if the stores table already exists
        if (Schema::hasTable('stores')) {
            // Table already exists, so we'll just add the store_id to pos_terminals if needed
            if (!Schema::hasColumn('pos_terminals', 'store_id')) {
                Schema::table('pos_terminals', function (Blueprint $table) {
                    $table->foreignId('store_id')->nullable()->after('tenant_id')->constrained();
                });
            }
            return;
        }

        // Check if tenant_id is a string by querying the schema information directly
        $tenantIdIsString = false;
        try {
            $tenantIdType = DB::select("SHOW COLUMNS FROM tenants WHERE Field = 'id'")[0]->Type;
            $tenantIdIsString = strpos(strtolower($tenantIdType), 'char') !== false || 
                               strpos(strtolower($tenantIdType), 'varchar') !== false ||
                               strpos(strtolower($tenantIdType), 'text') !== false;
        } catch (\Exception $e) {
            // Default to bigint if we can't determine the type
            $tenantIdIsString = false;
        }
        
        Schema::create('stores', function (Blueprint $table) use ($tenantIdIsString) {
            $table->id();
            
            // Match the tenant_id type with the tenants table id type
            if ($tenantIdIsString) {
                $table->string('tenant_id');
            } else {
                $table->unsignedBigInteger('tenant_id');
            }
            
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->json('operating_hours')->nullable();
            $table->string('status')->default('active');
            $table->boolean('allows_service_charge')->default(false);
            $table->boolean('tax_exempt')->default(false);
            $table->decimal('max_daily_sales', 12, 2)->nullable();
            $table->decimal('max_transaction_amount', 12, 2)->nullable();
            $table->timestamps();
        });
        
        // Add foreign key separately to ensure it's created correctly
        Schema::table('stores', function (Blueprint $table) {
            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onDelete('cascade');
        });
        
        // Add store_id to pos_terminals table
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('tenant_id')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Check if the store_id column exists in pos_terminals before attempting to drop it
        if (Schema::hasColumn('pos_terminals', 'store_id')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                $table->dropForeign(['store_id']);
                $table->dropColumn('store_id');
            });
        }
        
        if (Schema::hasTable('stores')) {
            Schema::dropIfExists('stores');
        }
    }
}