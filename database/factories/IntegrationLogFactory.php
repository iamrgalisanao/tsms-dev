<?php

namespace Database\Factories;

use App\Models\IntegrationLog;
use App\Models\PosTerminal;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IntegrationLog>
 */
class IntegrationLogFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = IntegrationLog::class;
    
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get random existing terminal and tenant, or create if none exist
        $terminal = PosTerminal::inRandomOrder()->first() ?? PosTerminal::factory()->create();
        $tenant = Tenant::inRandomOrder()->first() ?? Tenant::factory()->create();
        
        // Define statuses with weighted probabilities
        $statuses = ['SUCCESS', 'FAILED', 'PENDING'];
        $status = fake()->randomElement($statuses);
        
        // Generate retry count based on status
        $retryCount = $status === 'SUCCESS' ? fake()->numberBetween(0, 2) : fake()->numberBetween(1, 5);
        
        // Only failed transactions might need more retries
        $maxRetries = $status === 'FAILED' ? fake()->numberBetween($retryCount, 5) : $retryCount;
        
        return [
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => (string) Str::uuid(),
            'request_payload' => json_encode(['amount' => fake()->randomFloat(2, 10, 1000), 'items' => fake()->numberBetween(1, 10)]),
            'response_payload' => json_encode(['success' => $status === 'SUCCESS', 'message' => fake()->sentence()]),
            'status' => $status,
            'error_message' => $status === 'FAILED' ? fake()->sentence() : null,
            'http_status_code' => $status === 'SUCCESS' ? 200 : ($status === 'FAILED' ? fake()->randomElement([400, 422, 500]) : 202),
            'source_ip' => fake()->ipv4(),
            'retry_count' => $retryCount,
            'next_retry_at' => $status === 'FAILED' ? now()->addMinutes(fake()->numberBetween(5, 60)) : null,
            'retry_reason' => $retryCount > 0 ? fake()->sentence() : null,
            'validation_status' => fake()->randomElement(['PASSED', 'FAILED', null]),
            'response_time' => fake()->numberBetween(100, 5000),
            'retry_attempts' => $retryCount > 0 ? $retryCount - 1 : 0,
        ];
    }
    
    /**
     * Configure the model factory.
     *
     * @return $this
     */
    public function configure()
    {
        return $this->afterMaking(function (IntegrationLog $log) {
            // Do nothing after making
        })->afterCreating(function (IntegrationLog $log) {
            // If we need post-creation setup
        });
    }
    
    /**
     * Set log as a transaction log.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function transaction()
    {
        return $this->state(function (array $attributes) {
            return [
                'log_type' => 'transaction',
                'severity' => fake()->randomElement(['info', 'warning', 'error']),
                'message' => 'Transaction ' . ($attributes['status'] === 'SUCCESS' ? 'processed successfully' : 'failed to process')
            ];
        });
    }
    
    /**
     * Set log as an authentication log.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function auth()
    {
        return $this->state(function (array $attributes) {
            $user = User::inRandomOrder()->first() ?? User::factory()->create();
            
            return [
                'log_type' => 'auth',
                'user_id' => $user->id,
                'severity' => fake()->randomElement(['info', 'warning', 'error']),
                'message' => 'Authentication ' . ($attributes['status'] === 'SUCCESS' ? 'successful' : 'failed') . ' for user ' . $user->name
            ];
        });
    }
    
    /**
     * Set log as a security log.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function security()
    {
        return $this->state(function (array $attributes) {
            return [
                'log_type' => 'security',
                'severity' => fake()->randomElement(['warning', 'error']),
                'message' => 'Security event: ' . fake()->sentence()
            ];
        });
    }
}