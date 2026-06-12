<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('webapp_transaction_forwards');
    }

    public function down(): void
    {
        // WebApp forwarding has been removed from the application.
    }
};
