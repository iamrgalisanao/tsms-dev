<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('tenants', function (Blueprint $table) {
            // First drop the old code column
            $table->dropColumn('code');
            
            // Add new customer_code and other fields
            $table->string('customer_code')->after('name')->unique();
            $table->string('company_name')->after('customer_code')->nullable();
            $table->string('trade_name')->after('company_name')->nullable();
            $table->string('tin')->after('trade_name')->nullable();
            
            // Add location details
            $table->string('location')->after('tin')->nullable();
            $table->string('unit_no')->after('location')->nullable();
            $table->decimal('floor_area', 8, 2)->after('unit_no')->nullable();
            
            // Add classification details
            $table->string('category')->after('status')->nullable();
            $table->string('zone')->after('category')->nullable();
            $table->string('type')->after('zone')->nullable();

            // Create indexes
            $table->index('customer_code');
            $table->index('tin');
            $table->index('location');
            $table->index('category');
            $table->index('zone');
        });
    }

    public function down()
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['customer_code']);
            $table->dropIndex(['tin']);
            $table->dropIndex(['location']);
            $table->dropIndex(['category']);
            $table->dropIndex(['zone']);

            // Drop new columns
            $table->dropColumn([
                'customer_code',
                'company_name',
                'trade_name',
                'tin',
                'location',
                'unit_no',
                'floor_area',
                'category',
                'zone',
                'type'
            ]);

            // Restore original code column
            $table->string('code')->unique();
        });
    }
};