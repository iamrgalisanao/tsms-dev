<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Link back to companies (each company has its own customer_code)
            $table->foreignId('company_id')
                  ->constrained()
                  ->onDelete('restrict')
                  ->comment('FK to companies.id');

            // Business name
            $table->string('trade_name')
                  ->comment('Registered trade name of the tenant');

            // Location classification
            $table->enum('location_type', ['Kiosk','Inline'])
                  ->nullable()
                  ->comment('Physical location type');

            // Detailed location info
            $table->string('location')
                  ->nullable()
                  ->comment('Mall/area description');
            $table->string('unit_no', 50)
                  ->nullable()
                  ->comment('Unit or stall number');

            // Physical footprint
            $table->decimal('floor_area', 8, 2)
                  ->nullable()
                  ->comment('Floor area in square meters');

            // Operational status
            $table->enum('status', ['Operational','Not Operational'])
                  ->default('Operational')
                  ->index()
                  ->comment('Current operational state');

            // Tenant category
            $table->enum('category', ['F&B','Retail','Services'])
                  ->nullable()
                  ->comment('Business category');

            // Zone or section within the property
            $table->string('zone', 100)
                  ->nullable()
                  ->comment('Designated zone');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};