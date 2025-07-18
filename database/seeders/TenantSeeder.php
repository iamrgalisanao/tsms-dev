<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = 'G:/PITX/document/reports/sample/tenants_quoted_with_timestamps.csv';
        
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
                // Map CSV columns to tenant attributes directly
                $tenantData = [
                    // CSV columns: [customer_code, trade_name, location_type, location, unit_no, floor_area, status, category, zone, created_at, updated_at, deleted_at]
                    'customer_code' => trim($data[0] ?? ''),
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

                // Lookup company_id by customer_code
                $company = \App\Models\Company::where('customer_code', $tenantData['customer_code'])->first();
                if (!$company) {
                    Log::warning("Skipping tenant row due to non-existent customer_code", [
                        'customer_code' => $tenantData['customer_code'],
                        'trade_name' => $tenantData['trade_name'],
                        'row_data' => $data
                    ]);
            $this->command->warn("SKIP: No company found for customer_code '{$tenantData['customer_code']}' (trade_name: '{$tenantData['trade_name']}')");
                    $skipCount++;
                    continue;
                }
                $tenantData['company_id'] = $company->id;

                // Validate required fields
                if (empty($tenantData['trade_name']) || trim($tenantData['trade_name']) === '' || empty($tenantData['company_id'])) {
                    Log::warning("Skipping tenant row due to missing trade_name or company_id", [
                        'company_id' => $tenantData['company_id'],
                        'customer_code' => $tenantData['customer_code'],
                        'trade_name' => $tenantData['trade_name'],
                        'row_data' => $data
                    ]);
            $this->command->warn("SKIP: Missing trade_name or company_id for customer_code '{$tenantData['customer_code']}' (trade_name: '{$tenantData['trade_name']}')");
                    $skipCount++;
                    continue;
                }

                // Check if tenant already exists by trade_name and company_id to make seeder idempotent
                $existingTenant = Tenant::where('trade_name', $tenantData['trade_name'])
                    ->where('company_id', $tenantData['company_id'])
                    ->first();
                if ($existingTenant) {
                    Log::info("Tenant '{$tenantData['trade_name']}' for company '{$tenantData['customer_code']}' already exists, skipping.");
            $this->command->warn("SKIP: Tenant '{$tenantData['trade_name']}' for company_code '{$tenantData['customer_code']}' already exists.");
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