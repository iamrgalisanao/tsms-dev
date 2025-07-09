<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = 'g:\PITX\document\reports\sample\tenants_quoted_with_timestamps.csv';
        
        if (!file_exists($csvPath)) {
            Log::warning("CSV file not found at: {$csvPath}. Falling back to default tenants.");
            $this->createDefaultTenants();
            return;
        }

        $this->importFromCsv($csvPath);
    }

    private function importFromCsv(string $csvPath): void
    {
        $handle = fopen($csvPath, 'r');
        
        if ($handle === false) {
            Log::error("Failed to open CSV file: {$csvPath}");
            $this->createDefaultTenants();
            return;
        }

        // Read and skip header row
        $header = fgetcsv($handle);
        $importCount = 0;
        $skipCount = 0;

        while (($data = fgetcsv($handle)) !== false) {
            try {
                // Map CSV columns to tenant attributes
                $tenantData = [
                    'company_id' => trim($data[0] ?? ''),
                    'trade_name' => trim($data[1] ?? ''),
                    'location_type' => !empty(trim($data[2] ?? '')) ? trim($data[2]) : null,
                    'location' => !empty(trim($data[3] ?? '')) ? trim($data[3]) : null,
                    'unit_no' => !empty(trim($data[4] ?? '')) ? trim($data[4]) : null,
                    'floor_area' => !empty(trim($data[5] ?? '')) && trim($data[5]) !== '  ' ? (float) trim($data[5]) : null,
                    'status' => !empty(trim($data[6] ?? '')) ? trim($data[6]) : 'Operational',
                    'category' => !empty(trim($data[7] ?? '')) ? trim($data[7]) : null,
                    'zone' => !empty(trim($data[8] ?? '')) ? trim($data[8]) : null,
                    'created_at' => (!empty(trim($data[9] ?? '')) && trim($data[9]) !== 'null') ? trim($data[9]) : now(),
                    'updated_at' => (!empty(trim($data[10] ?? '')) && trim($data[10]) !== 'null') ? trim($data[10]) : now(),
                ];

                // Validate required fields
                if (empty($tenantData['company_id']) || empty($tenantData['trade_name']) || trim($tenantData['trade_name']) === '') {
                    Log::warning("Skipping tenant row due to missing required fields", [
                        'company_id' => $tenantData['company_id'],
                        'trade_name' => $tenantData['trade_name']
                    ]);
                    $skipCount++;
                    continue;
                }

                // Check if tenant already exists to make seeder idempotent
                $existingTenant = Tenant::where('company_id', $tenantData['company_id'])->first();
                
                if ($existingTenant) {
                    Log::info("Tenant with company_id {$tenantData['company_id']} already exists, skipping.");
                    $skipCount++;
                    continue;
                }

                Tenant::create($tenantData);
                $importCount++;

            } catch (\Exception $e) {
                Log::error("Error importing tenant row", [
                    'data' => $data,
                    'error' => $e->getMessage()
                ]);
                $skipCount++;
            }
        }

        fclose($handle);

        Log::info("Tenant import completed", [
            'imported' => $importCount,
            'skipped' => $skipCount,
            'total_processed' => $importCount + $skipCount
        ]);

        $this->command->info("Imported {$importCount} tenants, skipped {$skipCount} records.");
    }

    private function createDefaultTenants(): void
    {
        $defaultTenants = [
            [
                'company_id' => 'DEMO001',
                'trade_name' => 'Demo Company',
                'location_type' => 'Store',
                'location' => 'Ground Floor',
                'unit_no' => 'G-001',
                'floor_area' => 100.0,
                'status' => 'Operational',
                'category' => 'Retail',
                'zone' => 'Zone A',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'company_id' => 'TEST001',
                'trade_name' => 'Test Company',
                'location_type' => 'Kiosk',
                'location' => 'Second Floor',
                'unit_no' => 'S-001',
                'floor_area' => 50.0,
                'status' => 'Operational',
                'category' => 'Food',
                'zone' => 'Zone B',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($defaultTenants as $tenant) {
            $existingTenant = Tenant::where('company_id', $tenant['company_id'])->first();
            if (!$existingTenant) {
                Tenant::create($tenant);
            }
        }

        $this->command->info("Created default tenants as fallback.");
    }
}