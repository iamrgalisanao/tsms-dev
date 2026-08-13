<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 002-backfill-transaction-taxes, Slice 20 (T076) — see
     * specs/002-backfill-transaction-taxes/slice-20-readiness-verdict-brief.md.
     *
     * Adds a structured `transaction_taxes_null_count` field (COUNT(*) WHERE
     * transaction_pk IS NULL, at capture time) so the readiness-verdict
     * command never has to parse the verbatim `integrity_report` text to
     * find this number. Nullable: existing captures taken before this
     * column existed have no value for it, which the readiness-verdict
     * command correctly treats as "structural check missing," not zero.
     */
    public function up(): void
    {
        Schema::table('pre_run_integrity_captures', function (Blueprint $table) {
            $table->unsignedBigInteger('transaction_taxes_null_count')->nullable()->after('duplicate_check_summary');
        });
    }

    public function down(): void
    {
        Schema::table('pre_run_integrity_captures', function (Blueprint $table) {
            $table->dropColumn('transaction_taxes_null_count');
        });
    }
};
