<?php
/**
 * TSMS Ingestion Helper: Sharding Calculator
 * 
 * Usage: php tools/calculate_shard.php <tenant_id>
 */

if ($argc < 2) {
    echo "Usage: php tools/calculate_shard.php <tenant_id>\n";
    exit(1);
}

$tenantId = (int)$argv[1];
$shard = $tenantId % 8;
$queue = "transaction-processing:s{$shard}";

echo "\n--- TSMS Sharding Logic Audit ---\n";
echo "Tenant ID: {$tenantId}\n";
echo "Formula:   {$tenantId} % 8\n";
echo "Result:    Shard {$shard}\n";
echo "Queue:     {$queue}\n";
echo "---------------------------------\n\n";
