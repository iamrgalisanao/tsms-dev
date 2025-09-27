<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication, RefreshDatabase;

    /**
     * The base URL to use while testing the application.
     *
     * @var string
     */
    protected $baseUrl = 'http://localhost';

    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Refresh the application to ensure clean state
        $this->refreshApplication();
        // Ensure minimal lookup tables are seeded for tests to avoid FK violations
        $this->seedMinimalLookupTables();
    }

    /**
     * Seed minimal lookup tables used by feature tests (job_statuses, validation_statuses, etc.)
     * Call this from tests that need these rows or from a global setUp in future.
     */
    protected function seedMinimalLookupTables(): void
    {
        $requiredStatuses = [
            ['code' => 'PROCESSING', 'description' => 'Processing'],
            ['code' => 'FAILED', 'description' => 'Failed'],
            ['code' => 'COMPLETED', 'description' => 'Completed'],
        ];
        foreach ($requiredStatuses as $s) {
            \Illuminate\Support\Facades\DB::table('job_statuses')->updateOrInsert(['code' => $s['code']], ['description' => $s['description']]);
        }

        $validationStatuses = [
            ['code' => 'PENDING', 'description' => 'Pending'],
            ['code' => 'VALID', 'description' => 'Valid'],
            ['code' => 'INVALID', 'description' => 'Invalid'],
            ['code' => 'FAILED', 'description' => 'Failed'],
        ];
        foreach ($validationStatuses as $vs) {
            \Illuminate\Support\Facades\DB::table('validation_statuses')->updateOrInsert(['code' => $vs['code']], ['description' => $vs['description']]);
        }

        // Ensure a default company and tenant exist (many tests use tenant_id = 1)
        try {
            \Illuminate\Support\Facades\DB::table('companies')->updateOrInsert(
                ['id' => 1],
                [
                    'customer_code' => 'DEF001',
                    'company_name' => 'Default Test Company',
                    'tin' => '00000000000',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            \Illuminate\Support\Facades\DB::table('tenants')->updateOrInsert(
                ['id' => 1],
                [
                    'company_id' => 1,
                    'trade_name' => 'Default Test Tenant',
                    'location_type' => 'Inline',
                    'location' => 'Test Location',
                    'status' => 'Operational',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            // Ignore DB exceptions here; tests will surface schema mismatches explicitly.
        }
    }
}