<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transactions') || Schema::hasColumn('transactions', 'original_payload')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->json('original_payload')->nullable()->after('payload_checksum');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('transactions') || ! Schema::hasColumn('transactions', 'original_payload')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('original_payload');
        });
    }
};
