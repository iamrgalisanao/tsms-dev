<?php
// database/migrations/xxxx_xx_xx_create_pos_providers_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('pos_providers', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 100);
            $table->string('email', 255);
            $table->string('contact_person', 100);
            $table->string('contact_number', 20);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('pos_providers');
    }
};