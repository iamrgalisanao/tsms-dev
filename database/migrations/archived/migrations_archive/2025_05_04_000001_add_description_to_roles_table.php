<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('description')->nullable()->after('guard_name');
            // RBAC best practices: add display_name, is_system, and timestamps if not present
            $table->string('display_name')->nullable()->after('name');
            $table->boolean('is_system')->default(false)->after('description');
            if (!Schema::hasColumn('roles', 'created_at')) {
                $table->timestamps();
            }
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['description', 'display_name', 'is_system']);
            if (Schema::hasColumn('roles', 'created_at')) {
                $table->dropTimestamps();
            }
        });
    }
};