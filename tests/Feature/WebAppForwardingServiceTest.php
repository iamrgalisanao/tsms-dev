<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebAppForwardingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestSkipped('Legacy WebAppForwardingServiceTest removed; placeholder to silence warnings.');
    }

    public function test_placeholder(): void
    {
        $this->assertTrue(true);
    }
}
