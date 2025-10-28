<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Models\Transaction;

class ReceiptNoNormalizationTest extends TestCase
{
    public function test_preserves_leading_zeros_and_trims()
    {
        $tx = new Transaction();
        $tx->setReceiptNoAttribute("  00000000001  ");
        $this->assertEquals("00000000001", $tx->receipt_no);
    }

    public function test_collapses_internal_whitespace_and_removes_control_chars()
    {
        $tx = new Transaction();
        $raw = " A\t  B\nC \x00";
        $tx->setReceiptNoAttribute($raw);
        $this->assertEquals("A B C", $tx->receipt_no);
    }

    public function test_truncates_long_values()
    {
        $tx = new Transaction();
        $long = str_repeat('X', 200);
        $tx->setReceiptNoAttribute($long);
        $this->assertEquals(128, mb_strlen($tx->receipt_no, 'UTF-8'));
    }
}
