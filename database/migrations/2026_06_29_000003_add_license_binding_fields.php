<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'location_code')) {
                $table->string('location_code', 100)->nullable()->after('location')->index();
            }

            if (!Schema::hasColumn('tenants', 'deployment_id')) {
                $table->string('deployment_id')->nullable()->after('location_code')->index();
            }

            if (!Schema::hasColumn('tenants', 'license_id')) {
                $table->string('license_id')->nullable()->after('deployment_id')->index();
            }
        });

        Schema::table('pos_terminals', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_terminals', 'location_code')) {
                $table->string('location_code', 100)->nullable()->after('tenant_id')->index();
            }

            if (!Schema::hasColumn('pos_terminals', 'deployment_id')) {
                $table->string('deployment_id')->nullable()->after('location_code')->index();
            }

            if (!Schema::hasColumn('pos_terminals', 'license_id')) {
                $table->string('license_id')->nullable()->after('deployment_id')->index();
            }

            if (!Schema::hasColumn('pos_terminals', 'activation_status')) {
                $table->string('activation_status', 50)->nullable()->after('license_id')->index();
            }

            if (!Schema::hasColumn('pos_terminals', 'activated_at')) {
                $table->timestamp('activated_at')->nullable()->after('activation_status');
            }

            if (!Schema::hasColumn('pos_terminals', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->after('activated_at');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'deployment_id')) {
                $table->string('deployment_id')->nullable()->after('terminal_id')->index();
            }
        });

        if (!Schema::hasTable('terminal_activations')) {
            Schema::create('terminal_activations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('terminal_id')->nullable()->constrained('pos_terminals')->nullOnDelete();
                $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
                $table->string('license_id')->nullable()->index();
                $table->string('deployment_id')->nullable()->index();
                $table->string('location_code', 100)->nullable()->index();
                $table->string('activation_status', 50)->index();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['deployment_id', 'location_code']);
                $table->index(['terminal_id', 'activation_status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_activations');

        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'deployment_id')) {
                $table->dropColumn('deployment_id');
            }
        });

        Schema::table('pos_terminals', function (Blueprint $table) {
            foreach ([
                'location_code',
                'deployment_id',
                'license_id',
                'activation_status',
                'activated_at',
                'revoked_at',
            ] as $column) {
                if (Schema::hasColumn('pos_terminals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            foreach (['location_code', 'deployment_id', 'license_id'] as $column) {
                if (Schema::hasColumn('tenants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
