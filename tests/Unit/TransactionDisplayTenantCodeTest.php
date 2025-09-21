<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\PosTerminal;
use App\Models\Transaction;

class TransactionDisplayTenantCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefers_tenant_customer_code_over_transaction_customer_code(): void
    {
        $company = Company::factory()->create([
            'customer_code' => 'COMPX' . rand(100, 999),
        ]);

        $tenant = Tenant::factory()->create([
            'company_id' => $company->id,
            'customer_code' => 'TENANT' . rand(1000, 9999),
        ]);

        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $tx = Transaction::create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => 'TXN-' . uniqid(),
            'transaction_timestamp' => now(),
            'gross_sales' => 100,
            'net_sales' => 90,
            'customer_code' => $company->customer_code, // would be used if tenant code missing
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
        ]);

        $this->assertSame($tenant->customer_code, $tx->display_tenant_code);
    }

    public function test_falls_back_to_transaction_customer_code_when_tenant_code_missing(): void
    {
        $company = Company::factory()->create([
            'customer_code' => 'COMPY' . rand(100, 999),
        ]);

        $tenant = Tenant::factory()->create([
            'company_id' => $company->id,
            'customer_code' => null, // intentionally missing
        ]);

        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $tx = Transaction::create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => 'TXN-' . uniqid(),
            'transaction_timestamp' => now(),
            'gross_sales' => 100,
            'net_sales' => 90,
            'customer_code' => 'TXCUST' . rand(1000, 9999),
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
        ]);

        $this->assertSame($tx->customer_code, $tx->display_tenant_code);
    }

    public function test_falls_back_to_company_customer_code_when_both_missing(): void
    {
        $company = Company::factory()->create([
            'customer_code' => 'COMPZ' . rand(100, 999),
        ]);

        $tenant = Tenant::factory()->create([
            'company_id' => $company->id,
            'customer_code' => null,
        ]);

        $terminal = PosTerminal::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $tx = Transaction::create([
            'tenant_id' => $tenant->id,
            'terminal_id' => $terminal->id,
            'transaction_id' => 'TXN-' . uniqid(),
            'transaction_timestamp' => now(),
            'gross_sales' => 100,
            'net_sales' => 90,
            'customer_code' => '', // force empty to skip this level
            'validation_status' => Transaction::VALIDATION_STATUS_VALID,
        ]);

        $this->assertSame($company->customer_code, $tx->display_tenant_code);
    }
}
