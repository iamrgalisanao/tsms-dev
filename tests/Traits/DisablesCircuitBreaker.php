<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\Config;

trait DisablesCircuitBreaker
{
    protected function disableCircuitBreaker()
    {
        Config::set('circuit-breaker.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableCircuitBreaker();
    }
}