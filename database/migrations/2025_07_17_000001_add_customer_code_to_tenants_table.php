<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCustomerCodeToTenantsTable extends Migration
{
    public function up()
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('customer_code')->nullable()->after('company_id');
            $table->index('customer_code');
            // Uncomment below if you want a foreign key constraint:
            // $table->foreign('customer_code')->references('customer_code')->on('companies')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Uncomment below if you added a foreign key:
            // $table->dropForeign(['customer_code']);
            $table->dropIndex(['customer_code']);
            $table->dropColumn('customer_code');
        });
    }
}