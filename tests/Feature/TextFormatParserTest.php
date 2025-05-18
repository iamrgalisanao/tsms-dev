<?php

namespace Tests\Feature;

use App\Services\TransactionValidationService;
use App\Http\Middleware\TransformTextFormat;
use Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class TextFormatParserTest extends TestCase
{
    protected $validationService;
    protected $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a real instance of the validation service
        $this->validationService = new TransactionValidationService();
        
        // Create the middleware with the validation service injected
        $this->middleware = new TransformTextFormat($this->validationService);
    }

    /** @test */
    public function it_can_parse_key_colon_value_format()
    {
        $content = "tenant_id: C-T1005\ntransaction_id: tx-12345\ntransaction_timestamp: 2025-03-26T13:45:00Z\nvatable_sales: 12000.0\nnet_sales: 18137.0\nvat_exempt_sales: 6137.0\ngross_sales: 12345.67\nvat_amount: 1500.0\ntransaction_count: 1\npayload_checksum: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
        
        $result = $this->validationService->parseTextFormat($content);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('tenant_id', $result);
        $this->assertEquals('C-T1005', $result['tenant_id']);
        $this->assertEquals('tx-12345', $result['transaction_id']);
        $this->assertEquals('12345.67', $result['gross_sales']);
    }

    /** @test */
    public function it_can_parse_key_equals_value_format()
    {
        $content = "tenant_id=C-T1005\ntransaction_id=tx-12345\ntransaction_timestamp=2025-03-26T13:45:00Z\nvatable_sales=12000.0\nnet_sales=18137.0\nvat_exempt_sales=6137.0\ngross_sales=12345.67\nvat_amount=1500.0\ntransaction_count=1\npayload_checksum=e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
        
        $result = $this->validationService->parseTextFormat($content);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('tenant_id', $result);
        $this->assertEquals('C-T1005', $result['tenant_id']);
        $this->assertEquals('tx-12345', $result['transaction_id']);
        $this->assertEquals('12345.67', $result['gross_sales']);
    }

    /** @test */
    public function it_can_parse_key_space_value_format()
    {
        $content = "tenant_id C-T1005\ntransaction_id tx-12345\ntransaction_timestamp 2025-03-26T13:45:00Z\nvatable_sales 12000.0\nnet_sales 18137.0\nvat_exempt_sales 6137.0\ngross_sales 12345.67\nvat_amount 1500.0\ntransaction_count 1\npayload_checksum e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
        
        $result = $this->validationService->parseTextFormat($content);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('tenant_id', $result);
        $this->assertEquals('C-T1005', $result['tenant_id']);
        $this->assertEquals('tx-12345', $result['transaction_id']);
        $this->assertEquals('12345.67', $result['gross_sales']);
    }

    /** @test */
    public function it_can_parse_mixed_format()
    {
        $content = "tenant_id: C-T1005\ntransaction_id=tx-12345\ntransaction_timestamp 2025-03-26T13:45:00Z\nvatable_sales: 12000.0\nnet_sales=18137.0\nvat_exempt_sales 6137.0\ngross_sales: 12345.67\nvat_amount=1500.0\ntransaction_count 1\npayload_checksum: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
        
        $result = $this->validationService->parseTextFormat($content);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('tenant_id', $result);
        $this->assertEquals('C-T1005', $result['tenant_id']);
        $this->assertEquals('tx-12345', $result['transaction_id']);
        $this->assertEquals('12345.67', $result['gross_sales']);
    }

    /** @test */
    public function it_properly_normalizes_field_names()
    {
        $content = "TENANT_ID: C-T1005\nTRANSACTION_ID: tx-12345\nTX_TIMESTAMP: 2025-03-26T13:45:00Z\nGROSS: 12345.67\nVAT: 1500.0\nTX_COUNT: 1\nCHECKSUM: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
        
        $result = $this->validationService->parseTextFormat($content);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('tenant_id', $result);
        $this->assertArrayHasKey('transaction_id', $result);
        $this->assertArrayHasKey('transaction_timestamp', $result);
        $this->assertArrayHasKey('gross_sales', $result);
        $this->assertArrayHasKey('vat_amount', $result);
        $this->assertArrayHasKey('transaction_count', $result);
        $this->assertArrayHasKey('payload_checksum', $result);
    }

    /** @test */
    public function middleware_transforms_text_to_json()
    {
        // Create a mock request with text content
        $content = "tenant_id: C-T1005\ntransaction_id: tx-12345\ntransaction_timestamp: 2025-03-26T13:45:00Z\nvatable_sales: 12000.0\nnet_sales: 18137.0\nvat_exempt_sales: 6137.0\ngross_sales: 12345.67\nvat_amount: 1500.0\ntransaction_count: 1\npayload_checksum: e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855";
        
        $request = Request::create(
            '/api/v1/transactions',
            'POST',
            [],  // parameters
            [],  // cookies
            [],  // files
            ['CONTENT_TYPE' => 'text/plain'],  // server
            $content  // content
        );
        
        // Mock the next middleware
        $next = function ($req) {
            // Return the modified request for testing
            return $req;
        };
        
        // Run the middleware
        $transformedRequest = $this->middleware->handle($request, $next);
        
        // Check if the request was transformed correctly
        $this->assertEquals('C-T1005', $transformedRequest->input('tenant_id'));
        $this->assertEquals('tx-12345', $transformedRequest->input('transaction_id'));
        $this->assertEquals('12345.67', $transformedRequest->input('gross_sales'));
        $this->assertEquals('1', $transformedRequest->input('transaction_count'));
    }

    /** @test */
    public function middleware_only_transforms_transactions_endpoint()
    {
        // Create a mock request to a different endpoint
        $content = "tenant_id: C-T1005\ntransaction_id: tx-12345";
        
        $request = Request::create(
            '/api/v1/healthcheck',  // Different endpoint
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $content
        );
        
        // Setup original input for comparison
        $originalInput = $request->all();
        
        // Mock the next middleware
        $next = function ($req) {
            // Return the request for testing
            return $req;
        };
        
        // Run the middleware
        $transformedRequest = $this->middleware->handle($request, $next);
        
        // Check that the request wasn't transformed since it's not the transactions endpoint
        $this->assertEquals($originalInput, $transformedRequest->all());
    }
}