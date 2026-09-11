<?php

namespace App\Services\Terminals;

use App\Models\PosTerminal;

/**
 * Result of issuing a terminal credential. The plaintext is only ever held in
 * memory for the duration of the response that returns it to the caller.
 */
final class IssuedCredential
{
    /**
     * @param  list<string>  $abilities
     */
    public function __construct(
        public readonly PosTerminal $terminal,
        public readonly string $plainTextToken,
        public readonly array $abilities,
    ) {}
}
