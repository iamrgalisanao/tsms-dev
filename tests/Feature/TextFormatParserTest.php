<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\TransactionValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TextFormatParserTest extends TestCase
{
    protected $validationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validationService = app(TransactionValidationService::class);
    }

    public function test_parses_key_value_format()
    {
        $input = "TERMINAL_ID: ABC123\nAMOUNT: 100.00\nTYPE: PAYMENT";
        $expected = [
            'terminal_id' => 'ABC123',
            'amount' => '100.00',
            'type' => 'PAYMENT'
        ];

        $result = $this->validationService->parseTextFormat($input);
        $this->assertEquals($expected, $result);
    }

    public function test_parses_equals_format()
    {
        $input = "TERMINAL_ID=ABC123\nAMOUNT=100.00\nTYPE=PAYMENT";
        $expected = [
            'terminal_id' => 'ABC123',
            'amount' => '100.00',
            'type' => 'PAYMENT'
        ];

        $result = $this->validationService->parseTextFormat($input);
        $this->assertEquals($expected, $result);
    }

    public function test_parses_space_format()
    {
        $input = "TERMINAL_ID ABC123\nAMOUNT 100.00\nTYPE PAYMENT";
        $expected = [
            'terminal_id' => 'ABC123',
            'amount' => '100.00',
            'type' => 'PAYMENT'
        ];

        $result = $this->validationService->parseTextFormat($input);
        $this->assertEquals($expected, $result);
    }

    public function test_handles_mixed_formats()
    {
        $input = "TERMINAL_ID: ABC123\nAMOUNT=100.00\nTYPE PAYMENT";
        $expected = [
            'terminal_id' => 'ABC123',
            'amount' => '100.00',
            'type' => 'PAYMENT'
        ];

        $result = $this->validationService->parseTextFormat($input);
        $this->assertEquals($expected, $result);
    }

    public function test_handles_empty_input()
    {
        $result = $this->validationService->parseTextFormat('');
        $this->assertEquals([], $result);
    }

    public function test_handles_invalid_input()
    {
        $input = "###invalid###\n@@@random@@@";
        $result = $this->validationService->parseTextFormat($input);
        $this->assertEmpty($result);
    }

    public function test_handles_special_characters()
    {
        $input = "TERMINAL_ID: ABC-123#\nAMOUNT: 1,234.56\nNOTES: Special @ Note!";
        $expected = [
            'terminal_id' => 'ABC-123#',
            'amount' => '1,234.56',
            'notes' => 'Special @ Note!'
        ];

        $result = $this->validationService->parseTextFormat($input);
        $this->assertEquals($expected, $result);
    }

    public function test_handles_multiline_values()
    {
        $input = "TERMINAL_ID: ABC123\nDESCRIPTION: Line 1\nContinued: Line 2\nTYPE: PAYMENT";
        $expected = [
            'terminal_id' => 'ABC123',
            'description' => 'Line 1',
            'continued' => 'Line 2',
            'type' => 'PAYMENT'
        ];

        $result = $this->validationService->parseTextFormat($input);
        $this->assertEquals($expected, $result);
    }

    public function test_api_endpoint()
    {
        $input = "TERMINAL_ID: TEST123\nAMOUNT: 100.00\nTYPE: PAYMENT";
        
        $response = $this->call(
    'POST',
    '/api/v1/test-parser',
    [],
    [],
    [],
    ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
    $input
);

        $expectedJson = [
            'terminal_id' => 'TEST123',
            'amount' => '100.00',
            'type' => 'PAYMENT'
        ];

        $response->assertOk();
        $this->assertEquals($expectedJson, $response->json());
    }
}