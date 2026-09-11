<?php

namespace Tests\Unit\Services\Terminals;

use App\Services\Terminals\TerminalCredentialAuditor;
use PHPUnit\Framework\TestCase;

class TerminalCredentialAuditorSanitizeTest extends TestCase
{
    private TerminalCredentialAuditor $auditor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditor = new TerminalCredentialAuditor;
    }

    public function test_credential_shaped_keys_are_stripped_regardless_of_casing_or_separators(): void
    {
        $input = [
            'token' => 'x',
            'access_token' => 'x',
            'accessToken' => 'x',
            'AccessToken' => 'x',
            'bearerToken' => 'x',
            'bearer_token' => 'x',
            'plainTextToken' => 'x',
            'plain_text_token' => 'x',
            'plaintext' => 'x',
            'refresh_token' => 'x',
            'api_key' => 'x',
            'apiKey' => 'x',
            'secret' => 'x',
            'client_secret' => 'x',
            'password' => 'x',
            'authorization' => 'x',
            'X-Authorization' => 'x',
            'cookie' => 'x',
            'headers' => ['cookie' => 'x'],
            'credentials' => 'x',
            'safe' => 'kept',
        ];

        $clean = $this->auditor->sanitize($input);

        $this->assertSame(['safe' => 'kept'], $clean);
    }

    public function test_forensic_token_counters_and_nested_metadata_are_preserved(): void
    {
        $input = [
            'tokens_issued' => 1,
            'tokensIssued' => 1,
            'tokens_deleted_count' => 2,
            'tokens_deleted' => [
                ['id' => 7, 'name' => 'terminal-ABC', 'abilities' => ['transaction:create'], 'last_used_at' => null],
            ],
            'token_ids' => [7],
            'token_names' => ['terminal-ABC'],
            'tenant_id' => 3,
            'actor_type' => 'User',
        ];

        $this->assertSame($input, $this->auditor->sanitize($input));
    }

    public function test_nested_secrets_inside_allowed_containers_are_still_stripped(): void
    {
        $input = [
            'tokens_deleted' => [
                ['id' => 7, 'name' => 'terminal-ABC', 'plainTextToken' => 'leak', 'token' => 'leak'],
            ],
        ];

        $this->assertSame(
            ['tokens_deleted' => [['id' => 7, 'name' => 'terminal-ABC']]],
            $this->auditor->sanitize($input)
        );
    }
}
