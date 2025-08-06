<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('webapp_transaction_forwards', function (Blueprint $table) {
            $table->string('submission_uuid')->nullable()->after('transaction_id');
            $table->index('submission_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webapp_transaction_forwards', function (Blueprint $table) {
            $table->dropIndex(['submission_uuid']);
            $table->dropColumn('submission_uuid');
        });
    }
};
