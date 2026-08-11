<?php

namespace Database\Factories;

use App\Models\TaxBackfillRecord;
use App\Models\TaxBackfillRun;
use App\Models\Transaction;
use App\Services\Backfill\TaxBackfillOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaxBackfillRecordFactory extends Factory
{
    protected $model = TaxBackfillRecord::class;

    public function definition(): array
    {
        return [
            'run_id' => TaxBackfillRun::factory(),
            'transaction_pk' => Transaction::factory(),
            'tenant_id' => function (array $attributes) {
                return Transaction::find($attributes['transaction_pk'])?->tenant_id
                    ?? \App\Models\Tenant::factory()->create()->id;
            },
            'reconstructed_tax_rows' => [
                ['tax_type' => 'VAT', 'amount' => $this->faker->randomFloat(2, 1, 500)],
            ],
            'had_linked_rows_before' => false,
            'outcome' => TaxBackfillOutcome::Applied->value,
            'reason_code' => null,
            'archive_reference' => null,
        ];
    }

    public function applied(): static
    {
        return $this->state(fn (array $attributes) => [
            'outcome' => TaxBackfillOutcome::Applied->value,
            'reason_code' => null,
        ]);
    }

    public function skippedExisting(): static
    {
        return $this->state(fn (array $attributes) => [
            'outcome' => TaxBackfillOutcome::SkippedExisting->value,
            'had_linked_rows_before' => true,
            'reconstructed_tax_rows' => [],
            'reason_code' => null,
        ]);
    }

    public function quarantined(string $reasonCode = 'missing_payload'): static
    {
        return $this->state(fn (array $attributes) => [
            'outcome' => TaxBackfillOutcome::Quarantined->value,
            'reconstructed_tax_rows' => [],
            'reason_code' => $reasonCode,
        ]);
    }

    public function failed(string $reasonCode = 'db_error'): static
    {
        return $this->state(fn (array $attributes) => [
            'outcome' => TaxBackfillOutcome::Failed->value,
            'reconstructed_tax_rows' => [],
            'reason_code' => $reasonCode,
        ]);
    }
}
