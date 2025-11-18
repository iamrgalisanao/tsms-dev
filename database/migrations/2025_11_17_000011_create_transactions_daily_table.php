<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsDailyTable extends Migration
{
    public function up()
    {
        Schema::create('transactions_daily', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('terminal_id')->default(0);
            $table->date('date');

            $table->bigInteger('tx_count')->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->decimal('avg_amount', 18, 4)->default(0);

            $table->bigInteger('issues_count')->default(0);
            $table->decimal('issues_amount', 18, 4)->default(0);

            $table->timestamps();

            $table->primary(['tenant_id', 'date', 'terminal_id'], 'tx_daily_pk');
            $table->index(['tenant_id', 'date'], 'tx_daily_tenant_date_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('transactions_daily');
    }
}
