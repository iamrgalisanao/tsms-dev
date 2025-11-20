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
        // Add columns only if they don't yet exist (idempotent).
        if (!Schema::hasColumn('transactions_hourly', 'total_gross_amount')) {
            Schema::table('transactions_hourly', function (Blueprint $table) {
                $table->decimal('total_gross_amount', 18, 4)->default(0)->after('total_amount');
            });
        }

        if (!Schema::hasColumn('transactions_hourly', 'total_net_amount')) {
            Schema::table('transactions_hourly', function (Blueprint $table) {
                $table->decimal('total_net_amount', 18, 4)->default(0)->after('total_gross_amount');
            });
        }

        if (!Schema::hasColumn('transactions_hourly', 'total_discount_amount')) {
            Schema::table('transactions_hourly', function (Blueprint $table) {
                $table->decimal('total_discount_amount', 18, 4)->default(0)->after('total_net_amount');
            });
        }

        if (!Schema::hasColumn('transactions_hourly', 'total_tax_amount')) {
            Schema::table('transactions_hourly', function (Blueprint $table) {
                $table->decimal('total_tax_amount', 18, 4)->default(0)->after('total_discount_amount');
            });
        }

        if (!Schema::hasColumn('transactions_hourly', 'total_service_charge_amount')) {
            Schema::table('transactions_hourly', function (Blueprint $table) {
                $table->decimal('total_service_charge_amount', 18, 4)->default(0)->after('total_tax_amount');
            });
        }

        if (!Schema::hasColumn('transactions_hourly', 'void_count')) {
            Schema::table('transactions_hourly', function (Blueprint $table) {
                $table->bigInteger('void_count')->default(0)->after('issues_amount');
            });
        }

        if (!Schema::hasColumn('transactions_hourly', 'refunded_count')) {
            Schema::table('transactions_hourly', function (Blueprint $table) {
                $table->bigInteger('refunded_count')->default(0)->after('void_count');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transactions_hourly', function (Blueprint $table) {
            $cols = [
                'total_gross_amount', 'total_net_amount', 'total_discount_amount', 'total_tax_amount', 'total_service_charge_amount',
                'void_count', 'refunded_count'
            ];

            foreach ($cols as $c) {
                if (Schema::hasColumn('transactions_hourly', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
}
