<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FxTest extends TestCase
{
    public function testSameCurrencyReturnsAmount(): void
    {
        $this->assertSame(100.0, fx_convert_amount(100.0, 'EUR', 'EUR', 0.0));
        $this->assertSame(100.0, fx_convert_amount(100.0, 'try', 'TRY', 5.0));
    }

    public function testConversion(): void
    {
        $this->assertEquals(3650.0, fx_convert_amount(100.0, 'EUR', 'TRY', 36.5));
    }

    public function testZeroRateFallsBackToOriginalAmount(): void
    {
        $this->assertSame(100.0, fx_convert_amount(100.0, 'EUR', 'TRY', 0.0));
    }

    public function testRoundingToTwoDecimals(): void
    {
        $this->assertEquals(36.55, fx_convert_amount(1.0, 'USD', 'TRY', 36.549));
        $this->assertEquals(1.23, fx_convert_amount(1.0, 'USD', 'EUR', 1.234));
    }
}
