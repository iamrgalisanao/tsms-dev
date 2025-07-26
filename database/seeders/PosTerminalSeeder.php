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
                'serial_number' => 'J8N7C9P7P8943N',
                'machine_number' => '001',
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
        ];

        $importCount = 0;
        $skipCount = 0;

        foreach ($terminals as $terminalData) {
            try {
                // Provide a default/initial valid API token for each terminal
                $terminalData['api_key'] = 'DEFAULT_API_KEY_1234567890';
                $terminalData['registered_at'] = now();

                // Log terminal data for debugging
                Log::info('Attempting to insert terminal:', $terminalData);

                // Check if terminal already exists
                $existingTerminal = PosTerminal::where('serial_number', $terminalData['serial_number'])->first();
                if ($existingTerminal) {
                    // Overwrite existing record with new data
                    $existingTerminal->fill($terminalData);
                    $existingTerminal->created_at = now(); // Force-update created_at directly
                    $existingTerminal->save();
                    Log::info("Terminal with serial_number {$terminalData['serial_number']} already exists, updated.");
                    $importCount++;
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