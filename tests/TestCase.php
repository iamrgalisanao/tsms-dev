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
        ];
        foreach ($validationStatuses as $vs) {
            \Illuminate\Support\Facades\DB::table('validation_statuses')->updateOrInsert(['code' => $vs['code']], ['description' => $vs['description']]);
        }
    }
}