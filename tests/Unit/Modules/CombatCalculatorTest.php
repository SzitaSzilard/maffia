<?php
declare(strict_types=1);

namespace Tests\Unit\Modules;

use PHPUnit\Framework\TestCase;
use Netmafia\Modules\Combat\Domain\CombatCalculator;

/**
 * CombatCalculator Unit Tests
 * Teszteli a harci kalkulátor minden metódusát: 
 * védelmi bónusz, támadási bónusz, és cooldown számítás.
 */
class CombatCalculatorTest extends TestCase
{
    // ============================================================
    // Defense Bonus Tests
    // ============================================================

    public function testDefenseBonusWithZeroAmmo(): void
    {
        $this->assertEquals(0.0, CombatCalculator::calculateDefenseBonus(0));
    }

    public function testDefenseBonusWithNegativeAmmo(): void
    {
        $this->assertEquals(0.0, CombatCalculator::calculateDefenseBonus(-5));
    }

    public function testDefenseBonusWithOneAmmo(): void
    {
        $this->assertEquals(0.4, CombatCalculator::calculateDefenseBonus(1));
    }

    public function testDefenseBonusWithMaxAmmo(): void
    {
        $this->assertEquals(10.0, CombatCalculator::calculateDefenseBonus(999));
    }

    public function testDefenseBonusWithOverMaxAmmo(): void
    {
        // 999 felett is 10% marad (cap)
        $this->assertEquals(10.0, CombatCalculator::calculateDefenseBonus(5000));
    }

    public function testDefenseBonusWithMiddleAmmo(): void
    {
        $bonus = CombatCalculator::calculateDefenseBonus(500);
        // Lineáris: 0.4 + (500-1) * 0.00961923847 ≈ 5.2
        $this->assertGreaterThan(4.0, $bonus);
        $this->assertLessThan(6.0, $bonus);
    }

    public function testDefenseBonusIsMonotonicallyIncreasing(): void
    {
        $prev = 0.0;
        foreach ([0, 1, 10, 50, 100, 250, 500, 750, 999] as $ammo) {
            $current = CombatCalculator::calculateDefenseBonus($ammo);
            $this->assertGreaterThanOrEqual($prev, $current, "Defense bonus should increase with ammo ($ammo)");
            $prev = $current;
        }
    }

    // ============================================================
    // Attack Bonus Tests
    // ============================================================

    public function testAttackBonusWithZeroAmmo(): void
    {
        $this->assertEquals(0.0, CombatCalculator::calculateAttackBonus(0));
    }

    public function testAttackBonusWithOneAmmo(): void
    {
        $bonus = CombatCalculator::calculateAttackBonus(1);
        // 5 + (1/999) * 10 ≈ 5.01
        $this->assertEqualsWithDelta(5.01, $bonus, 0.01);
    }

    public function testAttackBonusWithMaxAmmo(): void
    {
        $this->assertEquals(15.0, CombatCalculator::calculateAttackBonus(999));
    }

    public function testAttackBonusWithOverMaxAmmo(): void
    {
        $this->assertEquals(15.0, CombatCalculator::calculateAttackBonus(2000));
    }

    public function testAttackBonusIsMonotonicallyIncreasing(): void
    {
        $prev = 0.0;
        foreach ([0, 1, 100, 500, 999] as $ammo) {
            $current = CombatCalculator::calculateAttackBonus($ammo);
            $this->assertGreaterThanOrEqual($prev, $current, "Attack bonus should increase with ammo ($ammo)");
            $prev = $current;
        }
    }

    // ============================================================
    // Cooldown Tests
    // ============================================================

    public function testCooldownWithNullLastAttack(): void
    {
        $this->assertEquals(0, CombatCalculator::calculateCooldownRemaining(null));
    }

    public function testCooldownWithRecentAttack(): void
    {
        // 1 másodperce támadott → van cooldown
        $lastAttack = date('Y-m-d H:i:s', time() - 1);
        $remaining = CombatCalculator::calculateCooldownRemaining($lastAttack, 15, 0);
        $this->assertGreaterThan(800, $remaining); // ~899 mp kellene (15p - 1s)
    }

    public function testCooldownWithOldAttack(): void
    {
        // 30 perce támadott → nincs cooldown (15 perces limit)
        $lastAttack = date('Y-m-d H:i:s', time() - 1800);
        $remaining = CombatCalculator::calculateCooldownRemaining($lastAttack, 15, 0);
        $this->assertEquals(0, $remaining);
    }

    public function testCooldownWithReduction(): void
    {
        // Most támadott, 50% cooldown reduction → 7.5 perc = 450 mp
        $lastAttack = date('Y-m-d H:i:s', time());
        $remaining = CombatCalculator::calculateCooldownRemaining($lastAttack, 15, 50);
        $this->assertLessThanOrEqual(450, $remaining);
        $this->assertGreaterThan(440, $remaining);
    }

    public function testCooldownWith100PercentReduction(): void
    {
        // 100% reduction → azonnal támadhat
        $lastAttack = date('Y-m-d H:i:s', time());
        $remaining = CombatCalculator::calculateCooldownRemaining($lastAttack, 15, 100);
        $this->assertEquals(0, $remaining);
    }
}
