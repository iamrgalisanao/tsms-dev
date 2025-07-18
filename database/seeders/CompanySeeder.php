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
        $csvPath = 'G:/PITX/document/reports/sample/fixed_companies_import.csv';
        
        // Check if CSV file exists
        if (!file_exists($csvPath)) {
            $this->command->error("CSV file not found at: {$csvPath}");
            return;
        }

        $this->command->info("Reading companies from CSV: {$csvPath}");

        try {
            // Clear existing companies carefully due to foreign key constraints
            $existingCount = DB::table('companies')->count();
            if ($existingCount > 0) {
                $this->command->warn("Found {$existingCount} existing companies. Skipping truncate due to foreign key constraints.");
                $this->command->info("Will insert new companies or update existing ones...");
            }

            // Read and parse CSV
            $csvData = array_map('str_getcsv', file($csvPath));
            $header = array_shift($csvData); // Remove header row
            
            $successCount = 0;
            $errorCount = 0;

            foreach ($csvData as $row) {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }

                // Map CSV columns to database fields
                $companyData = [
                    'customer_code' => trim($row[0]),
                    'company_name' => trim($row[1]),
                    'tin' => trim($row[2]),
                    'created_at' => !empty(trim($row[3])) ? trim($row[3]) : now(),
                    'updated_at' => !empty(trim($row[4])) ? trim($row[4]) : now(),
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