<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            
            // Identification fields
            $table->string('customer_code')->unique();
            $table->string('company_name')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('tin')->nullable();

            // Location details
            $table->string('location')->nullable();
            $table->string('unit_no')->nullable();
            $table->decimal('floor_area', 8, 2)->nullable();

            // Classification fields
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->string('category')->nullable();
            $table->string('zone')->nullable();
            $table->string('type')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index('customer_code');
            $table->index('tin');
            $table->index('location');
            $table->index('category');
            $table->index('zone');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tenants');
    }
};