<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transaction_validations') && ! Schema::hasColumn('transaction_validations', 'validation_status')) {
            Schema::table('transaction_validations', function (Blueprint $table) {
                $table->string('validation_status')->nullable()->after('transaction_pk')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transaction_validations') && Schema::hasColumn('transaction_validations', 'validation_status')) {
            Schema::table('transaction_validations', function (Blueprint $table) {
                $table->dropColumn('validation_status');
            });
        }
    }
};
