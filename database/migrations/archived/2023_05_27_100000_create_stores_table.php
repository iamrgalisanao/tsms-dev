<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStoresTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
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
        
        // Add store_id to pos_terminals table if needed
        if (Schema::hasTable('pos_terminals') && !Schema::hasColumn('pos_terminals', 'store_id')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                $table->foreignId('store_id')->nullable()->after('tenant_id')->constrained();
            });
        }
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