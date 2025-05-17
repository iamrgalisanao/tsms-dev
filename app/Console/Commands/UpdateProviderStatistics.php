<?php

namespace App\Console\Commands;

use App\Models\PosProvider;
use App\Models\PosTerminal;
use App\Models\ProviderStatistics;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateProviderStatistics extends Command
{
    protected $signature = 'provider:update-stats {--days=1 : Number of days to update}';
    protected $description = 'Update the provider statistics for terminal enrollments';

    public function handle()
    {
        $daysToUpdate = $this->option('days');
        $providers = PosProvider::all();
        $this->info("Updating provider statistics for {$providers->count()} providers...");
        
        $updateCount = 0;
        
        foreach ($providers as $provider) {
            // Update statistics for each day
            for ($day = 0; $day < $daysToUpdate; $day++) {
                $date = Carbon::now()->subDays($day)->format('Y-m-d');
                
                // Calculate statistics as of that date
                $totalTerminals = PosTerminal::where('provider_id', $provider->id)
                    ->where('enrolled_at', '<=', $date . ' 23:59:59')
                    ->count();
                    
                $activeTerminals = PosTerminal::where('provider_id', $provider->id)
                    ->where('enrolled_at', '<=', $date . ' 23:59:59')
                    ->where('status', 'active')
                    ->count();
                    
                $inactiveTerminals = $totalTerminals - $activeTerminals;
                
                // Calculate new enrollments for that specific day
                $newEnrollments = PosTerminal::where('provider_id', $provider->id)
                    ->whereDate('enrolled_at', $date)
                    ->count();
                    
                // Update or create the statistics record
                ProviderStatistics::updateOrCreate(
                    ['provider_id' => $provider->id, 'date' => $date],
                    [
                        'terminal_count' => $totalTerminals,
                        'active_terminal_count' => $activeTerminals,
                        'inactive_terminal_count' => $inactiveTerminals,
                        'new_enrollments' => $newEnrollments
                    ]
                );
                
                $updateCount++;
            }
        }
        
        $this->info("Updated $updateCount provider statistics records.");
        Log::info("Provider statistics updated: $updateCount records");
        
        return 0;
    }
}
