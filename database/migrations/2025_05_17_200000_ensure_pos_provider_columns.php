<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Skip creating the pos_providers table if it already exists
        if (!Schema::hasTable('pos_providers')) {
            Schema::create('pos_providers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->string('api_key')->nullable();
                $table->text('description')->nullable();
                $table->string('contact_email')->nullable();
                $table->string('contact_phone')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        } else {
            // Table exists, ensure all columns are present and properly typed
            Schema::table('pos_providers', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_providers', 'name')) {
                    $table->string('name');
                }
                if (!Schema::hasColumn('pos_providers', 'code')) {
                    $table->string('code')->unique();
                }
                if (!Schema::hasColumn('pos_providers', 'api_key')) {
                    $table->string('api_key')->nullable();
                }
                if (!Schema::hasColumn('pos_providers', 'description')) {
                    $table->text('description')->nullable();
                }
                if (!Schema::hasColumn('pos_providers', 'contact_email')) {
                    $table->string('contact_email')->nullable();
                }
                if (!Schema::hasColumn('pos_providers', 'contact_phone')) {
                    $table->string('contact_phone')->nullable();
                }
                if (!Schema::hasColumn('pos_providers', 'status')) {
                    $table->string('status')->default('active');
                }
            });
        }

        // Make sure pos_terminals has provider_id and enrolled_at columns
        if (Schema::hasTable('pos_terminals')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_terminals', 'provider_id')) {
                    $table->foreignId('provider_id')->nullable()->after('tenant_id');
                }
                if (!Schema::hasColumn('pos_terminals', 'enrolled_at')) {
                    $table->timestamp('enrolled_at')->nullable()->after('registered_at');
                }
            });
            
            // Add foreign key if not exists
            if (!Schema::hasColumn('pos_terminals', 'provider_id')) {
                Schema::table('pos_terminals', function (Blueprint $table) {
                    $table->foreign('provider_id')
                        ->references('id')
                        ->on('pos_providers')
                        ->nullOnDelete();
                });
            }
        }

        // Create provider_statistics table if it doesn't exist
        if (!Schema::hasTable('provider_statistics')) {
            Schema::create('provider_statistics', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('provider_id');
                $table->date('date');
                $table->integer('terminal_count')->default(0);
                $table->integer('active_terminal_count')->default(0);
                $table->integer('inactive_terminal_count')->default(0);
                $table->integer('new_enrollments')->default(0);
                $table->timestamps();
                
                $table->unique(['provider_id', 'date']);
                $table->foreign('provider_id')
                    ->references('id')
                    ->on('pos_providers')
                    ->cascadeOnDelete();
            });
        }

        // Insert some sample providers if table is empty
        if (DB::table('pos_providers')->count() === 0) {
            DB::table('pos_providers')->insert([
                [
                    'name' => 'ABC Point of Sale, Inc.',
                    'code' => 'ABC-POS',
                    'api_key' => md5(uniqid('abc', true)),
                    'description' => 'Major provider of retail point of sale systems',
                    'contact_email' => 'support@abcpos.example.com',
                    'contact_phone' => '555-123-4567',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now()
                ],
                [
                    'name' => 'XYZ Restaurant Systems',
                    'code' => 'XYZ-REST',
                    'api_key' => md5(uniqid('xyz', true)),
                    'description' => 'Restaurant POS specializing in food service',
                    'contact_email' => 'help@xyzrest.example.com',
                    'contact_phone' => '555-987-6543',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now()
                ],
                [
                    'name' => 'QuickSale Terminal Co.',
                    'code' => 'QUICK-TERM',
                    'api_key' => md5(uniqid('quick', true)),
                    'description' => 'Affordable POS systems for small businesses',
                    'contact_email' => 'service@quicksale.example.com',
                    'contact_phone' => '555-456-7890',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't drop tables in this migration since they might have existed before
        // This is an idempotent migration
    }
};