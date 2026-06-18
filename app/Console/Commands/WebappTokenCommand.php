<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Str;

class WebappTokenCommand extends Command
{
    /**
     * The name and signature of the console command.
     * action: create|revoke|list
     */
    protected $signature = 'webapp:token {action : create|revoke|list} {--email=service-webapp@tsms.local} {--name=webapp-machine-token} {--abilities=webapp:read} {--token= : Plain token to revoke}';

    /**
     * The console command description.
     */
    protected $description = 'Manage machine tokens for the Webapp (create|list|revoke)';

    public function handle(): int
    {
        $action = $this->argument('action');
        $email = $this->option('email');
        $name = $this->option('name');
        $abilities = array_filter(array_map('trim', explode(',', $this->option('abilities'))));

        switch ($action) {
            case 'create':
                return $this->createToken($email, $name, $abilities);
            case 'list':
                return $this->listTokens($email);
            case 'revoke':
                return $this->revokeToken($email, $this->option('token'));
            default:
                $this->error('Unknown action. Use create|list|revoke');
                return 1;
        }
    }

    protected function createToken(string $email, string $name, array $abilities): int
    {
        $this->info("Creating or finding service user: {$email}");

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Webapp Service', 'password' => bcrypt(Str::random(40))]
        );

        $result = $user->createToken($name, $abilities);

        // createToken returns an object that may contain plainTextToken
        $plain = $result->plainTextToken ?? null;
        if (! $plain) {
            // Fallback: try to compose from id|token format
            $this->error('Failed to retrieve plain text token.');
            return 1;
        }

        $this->line('Token created successfully. Store this value securely (it will not be shown again):');
        $this->newLine();
        $this->line($plain);
        $this->newLine();
        $this->info('Granted abilities: ' . implode(',', $abilities));

        return 0;
    }

    protected function listTokens(string $email): int
    {
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("User {$email} not found");
            return 1;
        }

        $tokens = $user->tokens()->get(['id', 'name', 'abilities', 'last_used_at', 'created_at']);
        if ($tokens->isEmpty()) {
            $this->info('No tokens found for ' . $email);
            return 0;
        }

        $rows = $tokens->map(function ($t) {
            return [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => implode(',', (array) $t->abilities),
                'last_used_at' => $t->last_used_at,
                'created_at' => $t->created_at,
            ];
        })->toArray();

        $this->table(['id', 'name', 'abilities', 'last_used_at', 'created_at'], $rows);
        return 0;
    }

    protected function revokeToken(string $email, ?string $plainToken): int
    {
        if (! $plainToken) {
            $this->error('Please provide --token=<plain-token-to-revoke>');
            return 1;
        }

        // Use Sanctum PersonalAccessToken helper to find the token model
        $tokenModel = PersonalAccessToken::findToken($plainToken);
        if ($tokenModel) {
            $tokenModel->delete();
            $this->info('Token revoked successfully.');
            return 0;
        }

        // If not found by plain token, attempt revocation by ID if numeric
        if (ctype_digit($plainToken)) {
            $model = PersonalAccessToken::find((int) $plainToken);
            if ($model) {
                $model->delete();
                $this->info('Token revoked by id successfully.');
                return 0;
            }
        }

        $this->error('Token not found');
        return 1;
    }
}
