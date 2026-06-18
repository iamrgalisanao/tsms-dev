<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSummaryJobsMetaTable extends Migration
{
    public function up()
    {
        Schema::create('summary_jobs_meta', function (Blueprint $table) {
            $table->string('summary_table')->primary();
            $table->dateTime('last_processed_bucket')->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->string('status')->nullable();
            $table->integer('rows_processed')->default(0);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('summary_jobs_meta');
    }
}
