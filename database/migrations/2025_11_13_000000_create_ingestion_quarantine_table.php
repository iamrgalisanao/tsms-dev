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
        Schema::create('ingestion_quarantine', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('submission_uuid')->nullable()->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('terminal_id')->nullable()->index();
            $table->longText('payload');
            $table->string('payload_checksum_received')->nullable();
            $table->string('payload_checksum_computed')->nullable();
            $table->string('status')->default('NEW')->index();
            $table->json('metadata')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ingestion_quarantine');
    }
};
