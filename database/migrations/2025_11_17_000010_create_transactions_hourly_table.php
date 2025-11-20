<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsHourlyTable extends Migration
{
    public function up()
    {
        Schema::create('transactions_hourly', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('terminal_id')->default(0);
            $table->dateTime('hour');

            $table->bigInteger('tx_count')->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->decimal('avg_amount', 18, 4)->default(0);
            $table->decimal('min_amount', 18, 4)->nullable();
            $table->decimal('max_amount', 18, 4)->nullable();
            // Financial breakdowns (minimum per-transaction money fields aggregated)
            $table->decimal('total_gross_amount', 18, 4)->default(0);
            $table->decimal('total_net_amount', 18, 4)->default(0);
            $table->decimal('total_discount_amount', 18, 4)->default(0);
            $table->decimal('total_tax_amount', 18, 4)->default(0);
            $table->decimal('total_service_charge_amount', 18, 4)->default(0);

            // Status / count fields
            $table->bigInteger('void_count')->default(0);
            $table->bigInteger('refunded_count')->default(0);

            // Optional sample/detail columns to support drilldowns (nullable)
            $table->unsignedBigInteger('sample_transaction_id')->nullable();
            $table->dateTime('sample_completed_at')->nullable();
            $table->string('sample_payment_method', 64)->nullable();
            $table->string('sample_channel', 64)->nullable();
            $table->string('sample_primary_category', 128)->nullable();

            $table->bigInteger('success_count')->default(0);
            $table->bigInteger('decline_count')->default(0);
            $table->bigInteger('issues_count')->default(0);
            $table->decimal('issues_amount', 18, 4)->default(0);
            $table->bigInteger('duplicate_count')->default(0);

            $table->timestamps();

            $table->primary(['tenant_id', 'hour', 'terminal_id'], 'tx_hour_pk');
            $table->index(['tenant_id', 'hour'], 'tx_hour_tenant_hour_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transactions_hourly');
    }
}
