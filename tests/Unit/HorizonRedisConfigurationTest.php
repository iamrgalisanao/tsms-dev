<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HorizonRedisConfigurationTest extends TestCase
{
    public function test_horizon_defaults_to_the_queue_redis_connection(): void
    {
        $horizonConfig = file_get_contents(__DIR__ . '/../../config/horizon.php');

        $this->assertStringContainsString(
            "'use'    => env('HORIZON_CONNECTION', env('QUEUE_REDIS_CONNECTION', 'default'))",
            $horizonConfig
        );
        $this->assertStringNotContainsString('QUEUE_HORIZON_FALLBACK', $horizonConfig);
    }

    public function test_default_queue_redis_connection_exists_in_database_redis_config(): void
    {
        $queueConfig = file_get_contents(__DIR__ . '/../../config/queue.php');
        $databaseConfig = file_get_contents(__DIR__ . '/../../config/database.php');

        $this->assertStringContainsString("'connection' => env('QUEUE_REDIS_CONNECTION', 'default')", $queueConfig);
        $this->assertStringContainsString("'default' => [", $databaseConfig);
    }
}
