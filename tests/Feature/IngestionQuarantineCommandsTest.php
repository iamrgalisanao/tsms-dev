<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Models\IngestionQuarantine;
use Illuminate\Support\Str;

class IngestionQuarantineCommandsTest extends TestCase
{
    public function setUp(): void
    {
        // Boot the application but avoid running the global migrations (some project migrations
        // use SQL not supported by sqlite in-memory). We'll create only the minimal
        // ingestion_quarantine table needed by these command tests.
        $this->app = $this->createApplication();

        // Ensure database is sqlite in-memory for isolation
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);

        // Create minimal ingestion_quarantine table schema
        Schema::create('ingestion_quarantine', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('submission_uuid')->nullable()->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('terminal_id')->nullable()->index();
            $table->longText('payload');
            $table->string('payload_checksum_received')->nullable();
            $table->string('payload_checksum_computed')->nullable();
            $table->string('status')->default('NEW')->index();
            $table->json('metadata')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamps();
        });
        // Intentionally do NOT call parent::setUp() to avoid running the
        // global migrations via RefreshDatabase (many project migrations use
        // SQL statements unsupported by sqlite in-memory). We only need the
        // minimal table created above for these command tests.
    }

    protected function tearDown(): void
    {
        // Clean up our temporary table
        Schema::dropIfExists('ingestion_quarantine');
        parent::tearDown();
    }

    public function test_list_command_shows_quarantine_records()
    {
        $r1 = IngestionQuarantine::create([
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => 1,
            'terminal_id' => 1,
            'payload' => json_encode(['foo' => 'bar']),
            'status' => 'NEW',
        ]);

        $r2 = IngestionQuarantine::create([
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => 2,
            'terminal_id' => 2,
            'payload' => json_encode(['hello' => 'world']),
            'status' => 'NEW',
        ]);

        \Artisan::call('ingestion:quarantine:list', ['--limit' => 10]);
        $output = \Artisan::output();

        $this->assertStringContainsString((string) $r1->submission_uuid, $output);
        $this->assertStringContainsString((string) $r2->submission_uuid, $output);
    }

    public function test_show_command_redacts_payload_by_default()
    {
        $payload = [
            'card_pan' => '4111111111111111',
            'customer' => 'Alice'
        ];

        $r = IngestionQuarantine::create([
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => 7,
            'terminal_id' => 9,
            'payload' => json_encode($payload),
            'status' => 'NEW',
        ]);

        \Artisan::call('ingestion:quarantine:show', ['id' => $r->id]);
        $output = \Artisan::output();

        $this->assertStringContainsString('submission_uuid', $output);
        $this->assertStringContainsString('REDACTED', $output);
        $this->assertStringNotContainsString('4111111111111111', $output);
    }

    public function test_replay_command_dry_run_increments_attempts_and_leaves_pending()
    {
        $r = IngestionQuarantine::create([
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => 5,
            'terminal_id' => 6,
            'payload' => json_encode(['x' => 'y']),
            'status' => 'NEW',
            'attempts' => 0,
        ]);

        \Artisan::call('ingestion:quarantine:replay', ['id' => $r->id]);

        $r->refresh();

        $this->assertEquals(1, $r->attempts);
        $this->assertEquals('pending', $r->status);
    }
}
