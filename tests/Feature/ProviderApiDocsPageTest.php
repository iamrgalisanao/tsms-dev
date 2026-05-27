<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProviderApiDocsPageTest extends TestCase
{
    public function test_provider_api_testing_docs_page_is_publicly_accessible(): void
    {
        $response = $this->get('/docs/pos-provider/api-testing');

        $response->assertOk()
            ->assertViewIs('app');
    }
}
