<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = '/Users/teamsolo/Downloads/tenants_quoted_with_timestamps.csv';
        
        if (!file_exists($csvPath)) {
            Log::warning("CSV file not found at: {$csvPath}. Falling back to default tenants.");
            $this->createDefaultTenants();
            return;
        }

        $this->importFromCsv($csvPath);
    }

    private function importFromCsv(string $csvPath): void
    {
        // Get the first company to use as default for all tenants
        // In a real scenario, you'd need proper company-tenant mapping
        $defaultCompany = \App\Models\Company::first();
        if (!$defaultCompany) {
            $this->command->error("No companies found. Please run CompanySeeder first.");
            return;
        }

        $this->command->info("Using company '{$defaultCompany->company_name}' (ID: {$defaultCompany->id}) for all tenants");

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
                    'company_id' => $defaultCompany->id, // Use actual company ID instead of CSV value
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
                    'deleted_at' => null, // Add deleted_at field with null value
                ];

                // Validate required fields
                if (empty($tenantData['trade_name']) || trim($tenantData['trade_name']) === '') {
                    Log::warning("Skipping tenant row due to missing trade_name", [
                        'trade_name' => $tenantData['trade_name'],
                        'row_data' => $data
                    ]);
                    $skipCount++;
                    continue;
                }

                // Check if tenant already exists by trade_name to make seeder idempotent
                $existingTenant = Tenant::where('trade_name', $tenantData['trade_name'])->first();
                
                if ($existingTenant) {
                    Log::info("Tenant '{$tenantData['trade_name']}' already exists, skipping.");
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
        // Get the first company to use as default
        $defaultCompany = \App\Models\Company::first();
        if (!$defaultCompany) {
            $this->command->error("No companies found for default tenants. Please run CompanySeeder first.");
            return;
        }

        $defaultTenants = [
            [
                'company_id' => $defaultCompany->id,
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
                'deleted_at' => null,
            ],
            [
                'company_id' => $defaultCompany->id,
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
                'deleted_at' => null,
            ],
        ];

        foreach ($defaultTenants as $tenant) {
            $existingTenant = Tenant::where('trade_name', $tenant['trade_name'])->first();
            if (!$existingTenant) {
                Tenant::create($tenant);
            }
        }

        $this->command->info("Created default tenants as fallback.");
    }
}