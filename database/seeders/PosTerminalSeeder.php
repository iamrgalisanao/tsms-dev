<?php

namespace Database\Seeders;

use App\Models\PosTerminal;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PosTerminalSeeder extends Seeder
{
    public function run(): void
    {
        $terminals = [
            // Jollibee terminals
            [
                'tenant_id' => 34, // Jollibee
                'serial_number' => 'JOLLIBEE_001_SN2025',
                'machine_number' => 'JB-MACHINE-001',
                'pos_type_id' => 1,
                'integration_type_id' => 1,
                'auth_type_id' => 1,
                'status_id' => 1,
                'is_active' => true,
                'supports_guest_count' => true,
                'notifications_enabled' => true,
                'callback_url' => 'https://jollibee-pos.example.com/webhook/tsms',
                'heartbeat_threshold' => 300, // 5 minutes
            ],
            [
                'tenant_id' => 34, // Jollibee
                'serial_number' => 'JOLLIBEE_002_SN2025',
                'machine_number' => 'JB-MACHINE-002',
                'pos_type_id' => 1,
                'integration_type_id' => 1,
                'auth_type_id' => 1,
                'status_id' => 1,
                'is_active' => true,
                'supports_guest_count' => true,
                'notifications_enabled' => true,
                'callback_url' => 'https://jollibee-pos.example.com/webhook/tsms',
                'heartbeat_threshold' => 300,
            ],
            // McDonald's terminals
            [
                'tenant_id' => 45, // McDonald's L1
                'serial_number' => 'MCDONALDS_L1_001_SN2025',
                'machine_number' => 'MD-L1-MACHINE-001',
                'pos_type_id' => 1,
                'integration_type_id' => 1,
                'auth_type_id' => 1,
                'status_id' => 1,
                'is_active' => true,
                'supports_guest_count' => false,
                'notifications_enabled' => true,
                'callback_url' => 'https://mcdonalds-pos.example.com/webhook/tsms',
                'heartbeat_threshold' => 300,
            ],
            [
                'tenant_id' => 45, // McDonald's L2
                'serial_number' => 'MCDONALDS_L2_001_SN2025',
                'machine_number' => 'MD-L2-MACHINE-001',
                'pos_type_id' => 1,
                'integration_type_id' => 1,
                'auth_type_id' => 1,
                'status_id' => 1,
                'is_active' => true,
                'supports_guest_count' => false,
                'notifications_enabled' => true,
                'callback_url' => 'https://mcdonalds-pos.example.com/webhook/tsms',
                'heartbeat_threshold' => 300,
            ],
            // KFC terminals
            [
                'tenant_id' => 61, // KFC
                'serial_number' => 'KFC_001_SN2025',
                'machine_number' => 'KFC-MACHINE-001',
                'pos_type_id' => 1,
                'integration_type_id' => 1,
                'auth_type_id' => 1,
                'status_id' => 1,
                'is_active' => true,
                'supports_guest_count' => true,
                'notifications_enabled' => true,
                'callback_url' => 'https://kfc-pos.example.com/webhook/tsms',
                'heartbeat_threshold' => 300,
            ],
            // Greenwich terminals
            [
                'tenant_id' => 1, // Dormitos
                'serial_number' => 'DORMITOS_DEMO_SN2025',
                'machine_number' => 'DORMITOS-MACHINE-001',
                'pos_type_id' => 1,
                'integration_type_id' => 1,
                'auth_type_id' => 1,
                'status_id' => 1,
                'is_active' => true,
                'supports_guest_count' => false,
                'notifications_enabled' => true,
                'callback_url' => 'https://dormitos-pos.example.com/webhook/tsms',
                'heartbeat_threshold' => 300,
            ],
            [
                'tenant_id' => 2, // Greenwich
                'serial_number' => 'GREENWICH_001_SN2025',
                'machine_number' => 'GW-MACHINE-001',
                'pos_type_id' => 1,
                'integration_type_id' => 1,
                'auth_type_id' => 1,
                'status_id' => 1,
                'is_active' => true,
                'supports_guest_count' => true,
                'notifications_enabled' => true,
                'callback_url' => 'https://greenwich-pos.example.com/webhook/tsms',
                'heartbeat_threshold' => 300,
            ],
            // Chowking terminals
            [
                'tenant_id' => 35, // Chowking
                'serial_number' => 'CHOWKING_001_SN2025',
                'machine_number' => 'CK-MACHINE-001',
                'pos_type_id' => 1,
                'integration_type_id' => 1,
                'auth_type_id' => 1,
                'status_id' => 1,
                'is_active' => true,
                'supports_guest_count' => true,
                'notifications_enabled' => true,
                'callback_url' => 'https://chowking-pos.example.com/webhook/tsms',
                'heartbeat_threshold' => 300,
            ],
            // 7-Eleven terminals
            [
                'tenant_id' => 40, // 7-Eleven
                'serial_number' => 'SEVEN_ELEVEN_001_SN2025',
                'machine_number' => '7E-MACHINE-001',
                'pos_type_id' => 1,
                'integration_type_id' => 1,
                'auth_type_id' => 1,
                'status_id' => 1,
                'is_active' => true,
                'supports_guest_count' => false,
                'notifications_enabled' => true,
                'callback_url' => 'https://7eleven-pos.example.com/webhook/tsms',
                'heartbeat_threshold' => 180, // 3 minutes for retail
            ],
            // Alfamart terminals
            [
                'tenant_id' => 4, // Alfamart
                'serial_number' => 'ALFAMART_001_SN2025',
                'machine_number' => 'AM-MACHINE-001',
                'pos_type_id' => 1,
                'integration_type_id' => 1,
                'auth_type_id' => 1,
                'status_id' => 1,
                'is_active' => true,
                'supports_guest_count' => false,
                'notifications_enabled' => true,
                'callback_url' => 'https://alfamart-pos.example.com/webhook/tsms',
                'heartbeat_threshold' => 180,
            ],
            // Subway terminals
            [
                'tenant_id' => 3, // Subway
                'serial_number' => 'SUBWAY_001_SN2025',
                'machine_number' => 'SW-MACHINE-001',
                'pos_type_id' => 1,
                'integration_type_id' => 1,
                'auth_type_id' => 1,
                'status_id' => 1,
                'is_active' => true,
                'supports_guest_count' => true,
                'notifications_enabled' => true,
                'callback_url' => 'https://subway-pos.example.com/webhook/tsms',
                'heartbeat_threshold' => 300,
            ],
        ];

        $importCount = 0;
        $skipCount = 0;

        foreach ($terminals as $terminalData) {
            try {
                // Generate unique API key for each terminal
                $terminalData['api_key'] = Str::random(64);
                $terminalData['registered_at'] = now();

                // Log terminal data for debugging
                Log::info('Attempting to insert terminal:', $terminalData);

                // Check if terminal already exists to make seeder idempotent
                $existingTerminal = PosTerminal::where('serial_number', $terminalData['serial_number'])->first();
                if ($existingTerminal) {
                    Log::info("Terminal with serial_number {$terminalData['serial_number']} already exists, skipping.");
                    $skipCount++;
                    continue;
                }

                // Verify tenant exists
                $tenant = Tenant::find($terminalData['tenant_id']);
                if (!$tenant) {
                    Log::warning("Tenant with ID {$terminalData['tenant_id']} not found, skipping terminal {$terminalData['serial_number']}.");
                    $skipCount++;
                    continue;
                } else {
                    Log::info("Tenant with ID {$terminalData['tenant_id']} found: " . json_encode($tenant->toArray()));
                }

                PosTerminal::create($terminalData);
                Log::info("Inserted terminal with serial_number {$terminalData['serial_number']}.");
                $importCount++;

            } catch (\Exception $e) {
                Log::error("Error importing terminal", [
                    'data' => $terminalData,
                    'error' => $e->getMessage()
                ]);
                $skipCount++;
            }
        }

        Log::info("POS Terminal import completed", [
            'imported' => $importCount,
            'skipped' => $skipCount,
            'total_processed' => $importCount + $skipCount
        ]);

        $this->command->info("Imported {$importCount} terminals, skipped {$skipCount} records.");
    }
}