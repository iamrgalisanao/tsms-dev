<?php

namespace Tests\Unit;

use App\Jobs\ProcessTransactionJob;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessTransactionJobTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_include_tenant_terminal_and_identifiers()
    {
        $tx = Transaction::factory()->create();

        $job = new ProcessTransactionJob($tx->id);
        $tags = $job->tags();

        $this->assertContains('domain:processing', $tags);
        $this->assertContains('transaction:pk=' . $tx->id, $tags);
        $this->assertContains('transaction:id=' . $tx->transaction_id, $tags);
        $this->assertContains('tenant:' . $tx->tenant_id, $tags);
        $this->assertContains('terminal:' . $tx->terminal_id, $tags);
    }
}
