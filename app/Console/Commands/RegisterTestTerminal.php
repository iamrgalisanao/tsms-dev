<?php

namespace App\Console\Commands;

use App\Models\PosTerminal;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class RegisterTestTerminal extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'terminal:register-test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register a test terminal and generate a JWT token for API testing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Get available columns from the database table
            $columns = DB::getSchemaBuilder()->getColumnListing('pos_terminals');
            $this->info('Available columns in pos_terminals table: ' . implode(', ', $columns));
            
            // Find or create a test tenant
            $tenant = Tenant::firstOrCreate(
                ['code' => 'TEST'],
                [
                    'name' => 'Test Tenant',
                    'code' => 'TEST',
                    'status' => 'active',
                    'contact_email' => 'test@example.com',
                    'contact_phone' => '123456789',
                ]
            );

            // Create a unique terminal UID
            $terminalUid = 'TEST-TERM-' . Str::random(6);
            
            // Create a JWT token (simplified for testing)
            $jwtToken = 'test_jwt_' . Str::random(32);
            
            // Prepare terminal data using only existing columns
            $terminalData = [
                'tenant_id' => $tenant->id,
                'terminal_uid' => $terminalUid,
                'status' => 'active',
                'registered_at' => now(),
                'jwt_token' => $jwtToken,
            ];
            
            // Create the terminal
            $terminal = PosTerminal::create($terminalData);
            
            $this->info('Test terminal registered successfully:');
            $this->info('Terminal UID: ' . $terminalUid);
            $this->info('JWT Token: ' . $jwtToken);
            $this->info('Use this token in your API requests:');
            $this->info('  Authorization: Bearer ' . $jwtToken);
            
            // Also provide instructions for testing with curl
            $this->info('');
            $this->info('For testing with curl:');
            $this->info('curl -X POST http://localhost:8000/api/v1/transactions \\');
            $this->info('  -H "Content-Type: text/plain" \\');
            $this->info('  -H "Authorization: Bearer ' . $jwtToken . '" \\');
            $this->info('  -d "tenant_id: TEST \\');
            $this->info('transaction_id: tx-' . Str::random(8) . ' \\');
            $this->info('transaction_timestamp: ' . now()->toIso8601String() . ' \\');
            $this->info('vatable_sales: 1000.00 \\');
            $this->info('net_sales: 1200.00 \\');
            $this->info('vat_exempt_sales: 200.00 \\');
            $this->info('gross_sales: 1200.00 \\');
            $this->info('vat_amount: 120.00 \\');
            $this->info('transaction_count: 1 \\');
            $this->info('payload_checksum: ' . md5(Str::random(16)) . '"');
            
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to register terminal: ' . $e->getMessage());
            $this->error('Trace: ' . $e->getTraceAsString());
            return 1;
        }
    }
}