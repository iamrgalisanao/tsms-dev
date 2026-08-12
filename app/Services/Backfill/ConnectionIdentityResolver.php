<?php

namespace App\Services\Backfill;

interface ConnectionIdentityResolver
{
    /**
     * @return array{server_id: int, database: string}
     */
    public function resolve(string $connectionName): array;
}
