<?php

namespace App\Services\Backfill;

use Illuminate\Support\Facades\DB;

class DatabaseConnectionIdentityResolver implements ConnectionIdentityResolver
{
    /**
     * @return array{server_id: int, database: string}
     */
    public function resolve(string $connectionName): array
    {
        $row = DB::connection($connectionName)->selectOne('SELECT @@server_id AS server_id, DATABASE() AS db_name');

        return [
            'server_id' => (int) $row->server_id,
            'database' => (string) $row->db_name,
        ];
    }
}
