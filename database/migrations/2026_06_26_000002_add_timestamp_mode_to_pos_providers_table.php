<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_providers', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_providers', 'timestamp_mode')) {
                $table->string('timestamp_mode', 32)
                    ->default('true_utc')
                    ->after(Schema::hasColumn('pos_providers', 'timezone') ? 'timezone' : 'name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_providers', function (Blueprint $table) {
            if (Schema::hasColumn('pos_providers', 'timestamp_mode')) {
                $table->dropColumn('timestamp_mode');
            }
        });
    }
};
