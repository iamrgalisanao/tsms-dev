<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            
            $table->string('tokenable_type');                // morph class
            $table->unsignedBigInteger('tokenable_id');      // morph PK
            $table->index(['tokenable_type', 'tokenable_id']);
            
            $table->string('name');                          // developer label
            
            $table->char('token', 64)
                  ->unique()
                  ->index()
                  ->comment('SHA256-hashed API token');
            
            $table->json('abilities')->nullable();           // scopes
            
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            // Index on expires_at for token cleanup
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
