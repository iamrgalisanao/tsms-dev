<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // No-op migration. Previously attempted to drop an index which is
        // environment-dependent and caused failures during automated runs.
    }

    public function down(): void
    {
        // Intentionally left blank.
    }
};
