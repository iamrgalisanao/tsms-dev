<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            // Add Terminal Metadata
            $table->enum('pos_type', ['F&B', 'Retail'])
                ->after('machine_number')
                ->nullable()
                ->comment('Used in reporting and validation logic');
                
            $table->boolean('supports_guest_count')
                ->after('pos_type')
                ->default(false)
                ->comment('Enables guest count validation for F&B');
            
            // Add Security & Integration
            $table->string('ip_whitelist')
                ->after('supports_guest_count')
                ->nullable();
                
            $table->string('device_fingerprint')
                ->after('ip_whitelist')
                ->nullable();
                
            $table->enum('integration_type', ['API', 'SFTP', 'Manual'])
                ->after('device_fingerprint')
                ->default('API');
                
            $table->enum('auth_type', ['JWT', 'API_KEY'])
                ->after('integration_type')
                ->default('JWT');

            // Add indexes if they don't exist
            if (!Schema::hasIndex('pos_terminals', 'pos_terminals_tenant_id_status_index')) {
                $table->index(['tenant_id', 'status']);
            }
            
            if (!Schema::hasIndex('pos_terminals', 'pos_terminals_terminal_uid_index')) {
                $table->index('terminal_uid');
            }
            
            if (!Schema::hasIndex('pos_terminals', 'pos_terminals_machine_number_index')) {
                $table->index('machine_number');
            }
        });
    }

    public function down()
    {
        Schema::table('pos_terminals', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['terminal_uid']);
            $table->dropIndex(['machine_number']);

            // Drop columns
            $table->dropColumn([
                'pos_type',
                'supports_guest_count',
                'ip_whitelist',
                'device_fingerprint',
                'integration_type',
                'auth_type'
            ]);
        });
    }
};