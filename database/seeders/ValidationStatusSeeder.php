<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ValidationStatus;

class ValidationStatusSeeder extends Seeder
{
    public function run()
    {
        $statuses = [
            ['code' => 'PENDING', 'description' => 'Validation has not yet been performed'],
            ['code' => 'VALID', 'description' => 'Transaction passed all validation rules'],
            ['code' => 'INVALID', 'description' => 'Transaction failed one or more validation rules'],
            ['code' => 'REVIEW_REQUIRED', 'description' => 'Validation inconclusive—needs human/manual review'],
            ['code' => 'ERROR', 'description' => 'System or processing error'],
        ];
        foreach ($statuses as $status) {
            ValidationStatus::updateOrCreate(['code' => $status['code']], $status);
        }
    }
}
