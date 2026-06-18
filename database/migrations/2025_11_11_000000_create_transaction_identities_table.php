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
        Schema::create('transaction_identities', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('terminal_id')->index();
            $table->char('canonical_fingerprint', 64)->index();
            $table->unsignedBigInteger('first_transaction_id')->nullable()->index();
            $table->timestamps();

            // Strong uniqueness per tenant+terminal+fingerprint ensures canonical identity
            $table->unique(['tenant_id', 'terminal_id', 'canonical_fingerprint'], 'txn_identity_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('transaction_identities');
    }
};
