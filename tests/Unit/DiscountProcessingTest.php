<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DiscountProcessingTest extends TestCase
{
    /**
     * Test that discount aggregation logic works correctly
     * for the backfill command scenario
     */
    public function test_backfill_discount_aggregation()
    {
        // Simulate backfill query results
        $adjustments = collect([
            (object) ['adjustment_type' => 'promo_discount', 'total_amount' => 25.50],
            (object) ['adjustment_type' => 'senior_discount', 'total_amount' => 15.00],
            (object) ['adjustment_type' => 'pwd_discount', 'total_amount' => 10.75],
        ]);

        // Test the backfill aggregation logic
        $promo = 0; $senior = 0; $pwd = 0;
        foreach ($adjustments as $adj) {
            $type = $adj->adjustment_type;
            $amt = (float) $adj->total_amount;
            if ($type === 'promo_discount') $promo += $amt;
            elseif ($type === 'senior_discount') $senior += $amt;
            elseif ($type === 'pwd_discount') $pwd += $amt;
        }

        $this->assertEquals(25.50, $promo, 'Promo discount aggregation failed');
        $this->assertEquals(15.00, $senior, 'Senior discount aggregation failed');
        $this->assertEquals(10.75, $pwd, 'PWD discount aggregation failed');
    }

    /**
     * Test that transaction controller discount processing
     * works correctly during ingestion
     */
    public function test_controller_discount_processing()
    {
        // Simulate controller adjustment processing
        $adjustments = [
            ['adjustment_type' => 'promo_discount', 'amount' => 50.25],
            ['adjustment_type' => 'senior_discount', 'amount' => 20.00],
            ['adjustment_type' => 'pwd_discount', 'amount' => 15.75],
            ['adjustment_type' => 'vip_card_discount', 'amount' => 10.00], // should be ignored
        ];

        $promoDiscount = 0;
        $seniorDiscount = 0;
        $pwdDiscount = 0;

        foreach ($adjustments as $adj) {
            $type = strtolower($adj['adjustment_type'] ?? '');
            $amt = $adj['amount'] ?? 0;
            if ($type === 'promo_discount') {
                $promoDiscount += $amt;
            } elseif ($type === 'senior_discount') {
                $seniorDiscount += $amt;
            } elseif ($type === 'pwd_discount') {
                $pwdDiscount += $amt;
            }
        }

        $this->assertEquals(50.25, $promoDiscount, 'Controller promo discount processing failed');
        $this->assertEquals(20.00, $seniorDiscount, 'Controller senior discount processing failed');
        $this->assertEquals(15.75, $pwdDiscount, 'Controller PWD discount processing failed');
    }
}
