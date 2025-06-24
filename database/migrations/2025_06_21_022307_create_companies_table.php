<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code')->unique();
            $table->string('company_name');
            $table->string('tin')->nullable();
            $table->timestamps();

            // Index for faster lookups
            $table->index('customer_code');
        });
    }

    public function down()
    {
        Schema::dropIfExists('companies');
    }
};