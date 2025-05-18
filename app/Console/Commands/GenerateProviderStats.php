<?php

namespace App\Console\Commands;

use App\Models\PosProvider;
use App\Models\ProviderStatistic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateProviderStats extends Command
{
    protected $signature = 'provider:stats {--date=}';
    protected $description = 'Generate daily statistics for POS providers';

    public function handle()
    {
        try {
            $date = $this->option('date') ? now()->parse($this->option('date')) : now();
            $this->info("Generating provider statistics for {$date->format('Y-m-d')}");

            $totalProviders = PosProvider::count();
            if ($totalProviders === 0) {
                $this->warn('No providers found in the system.');
                return 0;
            }

            $bar = $this->output->createProgressBar($totalProviders);
            $bar->start();

            $stats = [
                'processed' => 0,
                'updated' => 0,
                'errors' => 0
            ];

            PosProvider::chunk(100, function ($providers) use ($date, $bar, &$stats) {
                foreach ($providers as $provider) {
                    try {
                        ProviderStatistic::updateOrCreate(
                            [
                                'provider_id' => $provider->id,
                                'date' => $date->format('Y-m-d')
                            ],
                            [
                                'terminal_count' => $provider->terminals()->count(),
                                'active_terminal_count' => $provider->terminals()->where('status', 'active')->count(),
                                'inactive_terminal_count' => $provider->terminals()->where('status', '!=', 'active')->count(),
                                'new_enrollments' => $provider->terminals()
                                    ->whereDate('enrolled_at', $date->format('Y-m-d'))
                                    ->count()
                            ]
                        );
                        $stats['updated']++;
                    } catch (\Exception $e) {
                        Log::error("Error updating stats for provider {$provider->id}", [
                            'error' => $e->getMessage()
                        ]);
                        $stats['errors']++;
                    }
                    $stats['processed']++;
                    $bar->advance();
                }
            });

            $bar->finish();
            $this->newLine();
            
            $this->info("Statistics generation completed:");
            $this->table(
                ['Processed', 'Updated', 'Errors'],
                [[
                    $stats['processed'],
                    $stats['updated'],
                    $stats['errors']
                ]]
            );

            return $stats['errors'] === 0 ? 0 : 1;
        } catch (\Exception $e) {
            $this->error("Failed to generate statistics: {$e->getMessage()}");
            Log::error("Provider stats generation failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}