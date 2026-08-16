<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PriceRulesTest extends TestCase
{
    public function testPercentDiscount(): void
    {
        $rule = ['rule_type' => 'percent', 'value' => 10, 'name' => 'Erken rezervasyon', 'stackable' => true];
        $result = compute_rate_after_rules(200.0, [$rule]);
        $this->assertEquals(180.0, $result['price']);
        $this->assertSame(['Erken rezervasyon'], $result['applied']);
    }

    public function testFixedDiscount(): void
    {
        $rule = ['rule_type' => 'fixed', 'value' => 25.5, 'name' => 'Kanal indirimi', 'stackable' => false];
        $result = compute_rate_after_rules(100.0, [$rule]);
        $this->assertEquals(74.5, $result['price']);
    }

    public function testStackingStopsAtNonStackable(): void
    {
        $r1 = ['rule_type' => 'percent', 'value' => 10, 'name' => 'A', 'stackable' => true];
        $r2 = ['rule_type' => 'percent', 'value' => 20, 'name' => 'B', 'stackable' => false];
        $r3 = ['rule_type' => 'percent', 'value' => 50, 'name' => 'C', 'stackable' => true];
        $result = compute_rate_after_rules(100.0, [$r1, $r2, $r3]);
        // 100 -> 90 (A) -> 72 (B); C uygulanmaz.
        $this->assertEquals(72.0, $result['price']);
        $this->assertSame(['A', 'B'], $result['applied']);
    }

    public function testFreeNightDoesNotChangeNightlyPrice(): void
    {
        $rule = ['rule_type' => 'free_night', 'value' => 1, 'name' => '5. gece bedava', 'stackable' => true];
        $result = compute_rate_after_rules(150.0, [$rule]);
        $this->assertEquals(150.0, $result['price']);
        $this->assertSame([], $result['applied']);
    }

    public function testPriceNeverGoesNegative(): void
    {
        $rule = ['rule_type' => 'fixed', 'value' => 500, 'name' => 'Süper indirim', 'stackable' => true];
        $result = compute_rate_after_rules(100.0, [$rule]);
        $this->assertSame(0.0, $result['price']);
    }

    public function testPromoCodeMatchesCaseInsensitive(): void
    {
        $rule = [
            'rule_type' => 'promo_code', 'value' => 15, 'name' => 'PROMO15', 'promo_code' => 'PROMO15',
            'markets' => '[]', 'nationalities' => '[]', 'channels' => '[]', 'stackable' => true,
        ];
        $this->assertCount(1, filter_rate_rules([$rule], ['promo_code' => 'promo15', 'market' => 'TR', 'nationality' => 'TR', 'channel' => 'agency']));
    }

    public function testPromoCodeMismatchIsExcluded(): void
    {
        $rule = [
            'rule_type' => 'promo_code', 'value' => 15, 'name' => 'PROMO15', 'promo_code' => 'PROMO15',
            'markets' => '[]', 'nationalities' => '[]', 'channels' => '[]', 'stackable' => true,
        ];
        $this->assertCount(0, filter_rate_rules([$rule], ['promo_code' => 'YANLIS', 'market' => 'TR', 'nationality' => 'TR', 'channel' => 'agency']));
    }

    public function testMarketFilter(): void
    {
        $rule = [
            'rule_type' => 'percent', 'value' => 5, 'name' => 'Almanya pazarı',
            'markets' => '["DE"]', 'nationalities' => '[]', 'channels' => '[]', 'stackable' => true,
        ];
        $this->assertCount(1, filter_rate_rules([$rule], ['market' => 'DE']));
        $this->assertCount(0, filter_rate_rules([$rule], ['market' => 'TR']));
    }
}
