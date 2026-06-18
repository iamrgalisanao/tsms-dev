<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('security_events')) {
            return;
        }

        Schema::table('security_events', function (Blueprint $table) {
            if (!Schema::hasColumn('security_events', 'severity')) {
                $table->string('severity')->nullable()->after('event_type');
            }

            if (!Schema::hasColumn('security_events', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('severity');
            }

            if (!Schema::hasColumn('security_events', 'source_ip')) {
                $table->string('source_ip')->nullable()->after('user_id');
            }

            if (!Schema::hasColumn('security_events', 'context')) {
                $table->json('context')->nullable()->after('source_ip');
            }

            if (!Schema::hasColumn('security_events', 'event_timestamp')) {
                $table->timestamp('event_timestamp')->nullable()->after('context');
            }
        });

        // Try to add foreign key for user_id if possible
        try {
            if (Schema::hasTable('users') && !\Illuminate\Support\Facades\DB::selectOne("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME='security_events' AND COLUMN_NAME='user_id'")) {
                Schema::table('security_events', function (Blueprint $table) {
                    $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
                });
            }
        } catch (\Exception $e) {
            // ignore; best-effort
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('security_events')) {
            return;
        }

        Schema::table('security_events', function (Blueprint $table) {
            if (Schema::hasColumn('security_events', 'event_timestamp')) {
                $table->dropColumn('event_timestamp');
            }
            if (Schema::hasColumn('security_events', 'context')) {
                $table->dropColumn('context');
            }
            if (Schema::hasColumn('security_events', 'source_ip')) {
                $table->dropColumn('source_ip');
            }
            if (Schema::hasColumn('security_events', 'user_id')) {
                try { $table->dropForeign(['user_id']); } catch (\Exception $e) {}
                $table->dropColumn('user_id');
            }
            if (Schema::hasColumn('security_events', 'severity')) {
                $table->dropColumn('severity');
            }
        });
    }
};
