<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddHourlyAggregatesAndSamplesToTransactionsHourlyTable extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration targets the `reporting` connection where summary tables live.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('reporting')->table('transactions_hourly', function (Blueprint $table) {
            // Financial aggregates (denormalized sums)
            $table->decimal('total_gross_amount', 18, 4)->default(0)->after('total_amount');
            $table->decimal('total_net_amount', 18, 4)->default(0)->after('total_gross_amount');
            $table->decimal('total_discount_amount', 18, 4)->default(0)->after('total_net_amount');
            $table->decimal('total_tax_amount', 18, 4)->default(0)->after('total_discount_amount');
            $table->decimal('total_service_charge_amount', 18, 4)->default(0)->after('total_tax_amount');

            // Status counts
            $table->bigInteger('void_count')->default(0)->after('issues_amount');
            $table->bigInteger('refunded_count')->default(0)->after('void_count');

            // Optional sample/detail columns to support drilldowns
            $table->unsignedBigInteger('sample_transaction_id')->nullable()->after('refunded_count');
            $table->dateTime('sample_completed_at')->nullable()->after('sample_transaction_id');
            $table->string('sample_payment_method', 64)->nullable()->after('sample_completed_at');
            $table->string('sample_channel', 64)->nullable()->after('sample_payment_method');
            $table->string('sample_primary_category', 128)->nullable()->after('sample_channel');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('reporting')->table('transactions_hourly', function (Blueprint $table) {
            $cols = [
                'total_gross_amount', 'total_net_amount', 'total_discount_amount', 'total_tax_amount', 'total_service_charge_amount',
                'void_count', 'refunded_count', 'sample_transaction_id', 'sample_completed_at', 'sample_payment_method', 'sample_channel', 'sample_primary_category'
            ];

            foreach ($cols as $c) {
                if (Schema::connection('reporting')->hasColumn('transactions_hourly', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
}
