<?php

namespace Tests\Feature\TransactionPipeline;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\PosTerminal;
use App\Services\PayloadChecksumService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TransactionChildLinkingTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $tenant;
    protected $company;
    protected $terminal;
    protected $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(["customer_code" => 'TEST001']);
        $this->tenant = Tenant::factory()->create(["company_id" => $this->company->id, 'status' => 'active']);
        $this->terminal = PosTerminal::factory()->create(["tenant_id" => $this->tenant->id]);

        // Create a sanctum personal access token for the terminal to simulate bearer auth
        if (method_exists($this->terminal, 'createToken')) {
            $token = $this->terminal->createToken('test-token');
            $this->token = $token->plainTextToken ?? ($token->accessToken ?? null);
        } else {
            // Fallback: use a dummy token value (some test suites mock auth differently)
            $this->token = 'test-token-placeholder';
        }

        // Seed minimal lookup tables required by transaction processing
        $this->seedMinimalLookupTables();
    }

    public function test_adjustments_and_taxes_are_linked_via_transaction_pk()
    {
        $checksumService = new PayloadChecksumService();

        // Build a single transaction payload with enough adjustments/taxes to satisfy validation
        $transactionTimestamp = Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');

        // Scalars for transaction (must appear before payload_checksum)
        $transactionScalars = [
            'transaction_id' => (string) Str::uuid(),
            'transaction_timestamp' => $transactionTimestamp,
            'gross_sales' => 123.45,
            'net_sales' => 120.00,
            'promo_status' => 'NONE',
            'customer_code' => $this->company->customer_code,
        ];

        $adjustments = [
            ['adjustment_type' => 'promo_discount', 'amount' => 0.00],
            ['adjustment_type' => 'senior_discount', 'amount' => 0.00],
            ['adjustment_type' => 'pwd_discount', 'amount' => 0.00],
            ['adjustment_type' => 'vip_card_discount', 'amount' => 0.00],
            ['adjustment_type' => 'service_charge_distributed_to_employees', 'amount' => 0.00],
            ['adjustment_type' => 'service_charge_retained_by_management', 'amount' => 0.00],
            ['adjustment_type' => 'employee_discount', 'amount' => 0.00],
        ];

        $taxes = [
            ['tax_type' => 'VATABLE_SALES', 'amount' => 100.00],
            ['tax_type' => 'VAT', 'amount' => 12.00],
            ['tax_type' => 'SC_VAT_EXEMPT_SALES', 'amount' => 5.00],
            ['tax_type' => 'OTHER', 'amount' => 6.45],
        ];

        // Compute transaction checksum on structure without payload_checksum
        $transactionForChecksum = array_merge($transactionScalars, ['adjustments' => $adjustments, 'taxes' => $taxes]);
        $txnChecksum = $checksumService->computeChecksum($transactionForChecksum);

        // Construct transaction array with payload_checksum positioned before adjustments and taxes
        $transaction = array_merge($transactionScalars, ['payload_checksum' => $txnChecksum], ['adjustments' => $adjustments, 'taxes' => $taxes]);

        // Build submission scalars (payload_checksum must come before transaction key)
        $submissionTimestamp = Carbon::now()->utc()->format('Y-m-d\TH:i:s\Z');
        $submissionScalars = [
            'submission_uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'terminal_id' => $this->terminal->id,
            'submission_timestamp' => $submissionTimestamp,
            'transaction_count' => 1,
        ];

        // Compute submission checksum on submission that includes the transaction (which already has its payload_checksum)
        $submissionForChecksum = array_merge($submissionScalars, ['transaction' => $transaction]);
        $submissionChecksum = $checksumService->computeChecksum($submissionForChecksum);

        // Final submission with payload_checksum before transaction
        $submission = array_merge($submissionScalars, ['payload_checksum' => $submissionChecksum], ['transaction' => $transaction]);

        // Post to official endpoint
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json'
        ])->postJson('/api/v1/transactions/official', $submission);

        $response->assertStatus(200);

        // Verify transaction exists
        $this->assertDatabaseHas('transactions', [
            'transaction_id' => $transaction['transaction_id'],
            'terminal_id' => $this->terminal->id,
        ]);

        $tx = DB::table('transactions')->where('transaction_id', $transaction['transaction_id'])->first();
        $this->assertNotNull($tx, 'Transaction row not found');

        // Ensure adjustments count equals 7 and all have transaction_pk = tx.id
        $adjustCount = DB::table('transaction_adjustments')->where('transaction_pk', $tx->id)->count();
        $this->assertEquals(7, $adjustCount, 'Expected 7 adjustments linked to transaction via transaction_pk');

        // Ensure taxes count equals 4 and all have transaction_pk = tx.id
        $taxCount = DB::table('transaction_taxes')->where('transaction_pk', $tx->id)->count();
        $this->assertEquals(4, $taxCount, 'Expected 4 taxes linked to transaction via transaction_pk');
    }
}
