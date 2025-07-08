<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->string('callback_url', 500)->nullable()->after('status_id');
            $table->json('notification_preferences')->nullable()->after('callback_url');
            $table->boolean('notifications_enabled')->default(false)->after('notification_preferences');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            $table->dropColumn(['callback_url', 'notification_preferences', 'notifications_enabled']);
        });
    }
};