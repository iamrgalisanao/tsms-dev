<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\TransactionValidationService;

/**
 * This script manually tests the text format parser with various input formats
 */
class TextFormatParserTester
{
    protected $validator;
    
    public function __construct()
    {
        // Create the validator service
        $this->validator = new TransactionValidationService();
    }
    
    public function runTests()
    {
        echo "🧪 Testing POS Text Format Parser Implementation\n\n";
        
        $this->testKeyValueFormat();
        $this->testKeyEqualsValueFormat();
        $this->testSpaceSeparatedFormat();
        $this->testMixedFormat();
    }
    
    private function testKeyValueFormat()
    {
        echo "📝 Testing KEY: VALUE format...\n";
        
        $textInput = <<<EOT
tenant_id: C-T1005
hardware_id: 7P589L2
machine_number: 6
transaction_id: 8a918a90-7cbd-4b44-adc0-bc3d31cee238
trade_name: ABC Store #102
transaction_timestamp: 2025-03-26T13:45:00Z
vatable_sales: 12000.0
net_sales: 18137.0
vat_exempt_sales: 6137.0
promo_discount_amount: 100.0
promo_status: WITH_APPROVAL
discount_total: 50.0
discount_details: {"Employee": "20.00", "Senior": "30.00"}
other_tax: 50.0
management_service_charge: 8.5
employee_service_charge: 4.0
gross_sales: 12345.67
vat_amount: 1500.0
transaction_count: 1
EOT;

        $this->testParser($textInput, "KEY: VALUE format");
    }
    
    private function testKeyEqualsValueFormat()
    {
        echo "\n📝 Testing KEY=VALUE format...\n";
        
        $textInput = <<<EOT
tenant_id=C-T1005
hardware_id=7P589L2
machine_number=6
transaction_id=8a918a90-7cbd-4b44-adc0-bc3d31cee238
trade_name=ABC Store #102
transaction_timestamp=2025-03-26T13:45:00Z
vatable_sales=12000.0
net_sales=18137.0
vat_exempt_sales=6137.0
promo_discount_amount=100.0
promo_status=WITH_APPROVAL
discount_total=50.0
discount_details=Employee:20.00,Senior:30.00
other_tax=50.0
management_service_charge=8.5
employee_service_charge=4.0
gross_sales=12345.67
vat_amount=1500.0
transaction_count=1
EOT;

        $this->testParser($textInput, "KEY=VALUE format");
    }
    
    private function testSpaceSeparatedFormat()
    {
        echo "\n📝 Testing KEY VALUE format...\n";
        
        $textInput = <<<EOT
TENANT_ID C-T1005
HARDWARE_ID 7P589L2
MACHINE_NUMBER 6
TRANSACTION_ID 8a918a90-7cbd-4b44-adc0-bc3d31cee238
TRADE_NAME ABC Store #102
TRANSACTION_TIMESTAMP 2025-03-26T13:45:00Z
VATABLE_SALES 12000.0
NET_SALES 18137.0
VAT_EXEMPT_SALES 6137.0
PROMO_DISCOUNT_AMOUNT 100.0
PROMO_STATUS WITH_APPROVAL
DISCOUNT_TOTAL 50.0
DISCOUNT_DETAILS Employee;20.00;Senior;30.00
OTHER_TAX 50.0
MANAGEMENT_SERVICE_CHARGE 8.5
EMPLOYEE_SERVICE_CHARGE 4.0
GROSS_SALES 12345.67
VAT_AMOUNT 1500.0
TRANSACTION_COUNT 1
EOT;

        $this->testParser($textInput, "KEY VALUE format");
    }
    
    private function testMixedFormat()
    {
        echo "\n📝 Testing Mixed format...\n";
        
        $textInput = <<<EOT
tenant_id: C-T1005
hardware_id=7P589L2
MACHINE_NUMBER 6
transaction_id: 8a918a90-7cbd-4b44-adc0-bc3d31cee238
trade_name=ABC Store #102
TRANSACTION_TIMESTAMP 2025-03-26T13:45:00Z
vatable_sales: 12000.0
net_sales=18137.0
VAT_EXEMPT_SALES 6137.0
promo_discount_amount: 100.0
promo_status=WITH_APPROVAL
DISCOUNT_TOTAL 50.0
discount_details: {"Employee": "20.00", "Senior": "30.00"}
other_tax=50.0
MANAGEMENT_SERVICE_CHARGE 8.5
employee_service_charge: 4.0
gross_sales=12345.67
VAT_AMOUNT 1500.0
transaction_count: 1
EOT;

        $this->testParser($textInput, "Mixed format");
    }
    
    private function testParser($textInput, $formatName)
    {
        try {
            // Call the parser
            $result = $this->validator->parseTextFormat($textInput);
            
            echo "✅ Parser handled {$formatName} successfully\n";
            
            // Check key fields
            $requiredFields = [
                'tenant_id', 'hardware_id', 'transaction_id', 'gross_sales',
                'net_sales', 'vatable_sales', 'transaction_timestamp'
            ];
            
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (!isset($result[$field]) || empty($result[$field])) {
                    $missingFields[] = $field;
                }
            }
            
            if (empty($missingFields)) {
                echo "✅ All required fields were parsed correctly\n";
            } else {
                echo "❌ Missing required fields: " . implode(", ", $missingFields) . "\n";
            }
            
            // Check numeric field types
            $numericFields = ['gross_sales', 'net_sales', 'vatable_sales'];
            foreach ($numericFields as $field) {
                if (isset($result[$field]) && is_numeric($result[$field])) {
                    echo "✅ {$field}: {$result[$field]} (numeric format correctly parsed)\n";
                } else {
                    echo "❌ {$field}: " . (isset($result[$field]) ? $result[$field] : 'MISSING') . " (not numeric)\n";
                }
            }
            
            // Check discount details parsing
            if (isset($result['discount_details']) && is_array($result['discount_details'])) {
                echo "✅ discount_details successfully parsed into associative array\n";
            } else {
                echo "❌ discount_details parsing failed\n";
            }
            
            // Check checksum generation
            if (isset($result['payload_checksum']) && !empty($result['payload_checksum'])) {
                echo "✅ payload_checksum successfully generated\n";
            } else {
                echo "❌ payload_checksum generation failed\n";
            }
            
        } catch (\Exception $e) {
            echo "❌ Parser FAILED on {$formatName}: " . $e->getMessage() . "\n";
        }
    }
}

// Run the tests
$tester = new TextFormatParserTester();
$tester->runTests();
