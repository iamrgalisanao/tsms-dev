<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\JobStatus;

class JobStatusSeeder extends Seeder
{
    public function run()
    {
        $statuses = [
            ['code' => 'QUEUED', 'description' => 'Job has been created and is awaiting execution'],
            ['code' => 'RUNNING', 'description' => 'Job is currently in progress'],
            ['code' => 'RETRYING', 'description' => 'Job failed but is scheduled for another attempt'],
            ['code' => 'COMPLETED', 'description' => 'Job finished successfully'],
            ['code' => 'PERMANENTLY_FAILED', 'description' => 'Job has failed after maximum retries and will not be retried'],
        ];
        foreach ($statuses as $status) {
            JobStatus::updateOrCreate(['code' => $status['code']], $status);
        }
    }
}
