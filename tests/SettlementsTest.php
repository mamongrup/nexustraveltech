<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SettlementsTest extends TestCase
{
    public function testSettlementCalculation(): void
    {
        $result = settlement_calculation(340.0, 10);
        $this->assertEquals(340.0, $result['gross']);
        $this->assertEquals(34.0, $result['commission_amount']);
        $this->assertEquals(306.0, $result['net_amount']);
    }

    public function testZeroCommission(): void
    {
        $result = settlement_calculation(88.88, 0);
        $this->assertEquals(0.0, $result['commission_amount']);
        $this->assertEquals(88.88, $result['net_amount']);
    }

    public function testCommissionRateClampedTo100(): void
    {
        $result = settlement_calculation(100.0, 150);
        $this->assertEquals(100.0, $result['commission_amount']);
        $this->assertEquals(0.0, $result['net_amount']);
    }

    public function testCommissionRateClampedToZero(): void
    {
        $result = settlement_calculation(100.0, -10);
        $this->assertEquals(0.0, $result['commission_amount']);
        $this->assertEquals(100.0, $result['net_amount']);
    }

    public function testRounding(): void
    {
        $result = settlement_calculation(99.99, 7);
        // 99.99 * 0.07 = 6.9993 -> 7.00
        $this->assertEquals(7.0, $result['commission_amount']);
        $this->assertEquals(92.99, $result['net_amount']);
    }
}
