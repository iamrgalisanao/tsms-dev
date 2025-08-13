<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Determine CSV path using precedence:
        // 1. ENV COMPANY_CSV_PATH
        // 2. database/seeders/data/fixed_companies_import.csv
        // 3. storage/app/seeds/fixed_companies_import.csv
        // 4. project root fixed_companies_import.csv
        // 5. $HOME/Downloads/fixed_companies_import.csv
        $envPath = env('COMPANY_CSV_PATH');
        $candidatePaths = array_filter([
            $envPath,
            base_path('database/seeders/data/fixed_companies_import.csv'),
            storage_path('app/seeds/fixed_companies_import.csv'),
            base_path('fixed_companies_import.csv'),
            rtrim(getenv('HOME') ?: '', '/') . '/Downloads/fixed_companies_import.csv',
        ]);

        $csvPath = null;
        foreach ($candidatePaths as $path) {
            if ($path && file_exists($path)) { $csvPath = $path; break; }
        }

        if (!$csvPath) {
            $this->command->error('CompanySeeder: CSV file not found. Tried:');
            foreach ($candidatePaths as $p) { $this->command->warn(' - ' . $p); }
            $this->command->warn('Provide a file and either set COMPANY_CSV_PATH or place it in database/seeders/data/.');
            return;
        }

        $this->command->info("CompanySeeder using CSV: {$csvPath}");

        try {
            // Clear existing companies carefully due to foreign key constraints
            $existingCount = DB::table('companies')->count();
            if ($existingCount > 0) {
                $this->command->warn("Found {$existingCount} existing companies. Skipping truncate due to foreign key constraints.");
                $this->command->info("Will insert new companies or update existing ones...");
            }

            // Read and parse CSV (supports UTF-8 BOM)
            $raw = file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$raw) {
                $this->command->error('CSV file appears empty.');
                return;
            }
            $first = $raw[0];
            // Strip BOM if present
            $raw[0] = preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $csvData = array_map('str_getcsv', $raw);
            $header = array_shift($csvData); // header row
            $headerCount = count($header);
            
            $successCount = 0;
            $errorCount = 0;

            foreach ($csvData as $row) {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Pad row if shorter than header to avoid undefined offsets
                if (count($row) < $headerCount) {
                    $row = array_pad($row, $headerCount, null);
                }

                // Flexible mapping: expecting columns [customer_code, company_name, tin, created_at?, updated_at?]
                $companyData = [
                    'customer_code' => trim((string)$row[0]),
                    'company_name'  => trim((string)$row[1]),
                    'tin'           => trim((string)$row[2]),
                    'created_at'    => isset($row[3]) && trim((string)$row[3]) !== '' ? trim((string)$row[3]) : now(),
                    'updated_at'    => isset($row[4]) && trim((string)$row[4]) !== '' ? trim((string)$row[4]) : now(),
                ];

                try {
                    // Validate required fields
                    if (empty($companyData['customer_code']) || empty($companyData['company_name'])) {
                        $this->command->warn("Skipping row with missing customer_code or company_name: " . json_encode($row));
                        $errorCount++;
                        continue;
                    }

                    // Insert or update company data
                    DB::table('companies')->updateOrInsert(
                        ['customer_code' => $companyData['customer_code']], // Search condition
                        $companyData // Data to insert or update
                    );
                    $successCount++;

                    if ($successCount % 10 == 0) {
                        $this->command->info("Processed {$successCount} companies...");
                    }

                } catch (\Exception $e) {
                    $this->command->error("Error inserting company: " . $e->getMessage());
                    $this->command->error("Row data: " . json_encode($row));
                    $errorCount++;
                }
            }

            $this->command->info("✅ Successfully imported {$successCount} companies");
            if ($errorCount > 0) {
                $this->command->warn("⚠️  {$errorCount} rows had errors");
            }

            // Show some statistics
            $totalCompanies = DB::table('companies')->count();
            $uniqueCustomerCodes = DB::table('companies')->distinct('customer_code')->count();
            
            $this->command->info("📊 Database Statistics:");
            $this->command->info("   Total companies: {$totalCompanies}");
            $this->command->info("   Unique customer codes: {$uniqueCustomerCodes}");

            // Show sample of imported data
            $sampleCompanies = DB::table('companies')
                ->select('customer_code', 'company_name', 'tin')
                ->limit(5)
                ->get();

            $this->command->info("📋 Sample imported companies:");
            foreach ($sampleCompanies as $company) {
                $this->command->info("   {$company->customer_code} - {$company->company_name}");
            }

        } catch (\Exception $e) {
            $this->command->error("Failed to import companies: " . $e->getMessage());
            Log::error("CompanySeeder failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}