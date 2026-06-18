<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('tenants') && !Schema::hasColumn('tenants', 'accept_with_issues')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->boolean('accept_with_issues')->default(false)->after('status')->index();
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
        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'accept_with_issues')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropIndex(['accept_with_issues']);
                $table->dropColumn('accept_with_issues');
            });
        }
    }
};
