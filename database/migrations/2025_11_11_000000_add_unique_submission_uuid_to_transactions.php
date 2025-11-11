<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds a UNIQUE index on transactions.submission_uuid only when no
     * duplicate submission_uuid values exist. If duplicates are present the
     * migration will log and skip the index so it can be handled manually.
     */
    public function up(): void
    {
        // quick check for duplicates
        $duplicates = DB::select(
            "SELECT COUNT(*) AS c FROM (SELECT submission_uuid FROM transactions WHERE submission_uuid IS NOT NULL AND submission_uuid <> '' GROUP BY submission_uuid HAVING COUNT(*) > 1) t"
        );

        $count = 0;
        if (is_array($duplicates) && isset($duplicates[0]->c)) {
            $count = (int) $duplicates[0]->c;
        }

        if ($count > 0) {
            Log::warning('Skipping creation of unique index on transactions.submission_uuid: duplicate submission_uuid groups found', ['duplicate_groups' => $count]);
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            // add unique index (named for clarity)
            $table->unique('submission_uuid', 'ux_transactions_submission_uuid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('ux_transactions_submission_uuid');
        });
    }
};
