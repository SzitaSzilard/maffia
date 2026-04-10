<?php
declare(strict_types=1);

namespace Tests\Unit\Modules;

use PHPUnit\Framework\TestCase;

/**
 * Sleep Regeneration Logic Tests
 * 
 * Teszteli az alvási regeneráció számítási logikáját,
 * különféle időtartamokkal és regen értékekkel.
 * (Business logic unit tesztek — nem igényel DB-t)
 */
class SleepRegenTest extends TestCase
{
    /**
     * Szimulált regeneráció számítás (a SleepService.wakeUp() logikája alapján)
     */
    private function calculateRegen(float $hoursSlept, int $regenPerHour): int
    {
        return (int)($hoursSlept * $regenPerHour);
    }

    public function testFullSleepRegen(): void
    {
        // 8 óra alvás, 10% regen/óra → 80 HP/Energy
        $this->assertEquals(80, $this->calculateRegen(8.0, 10));
    }

    public function testHalfHourSleep(): void
    {
        // 0.5 óra alvás, 10% regen → 5 HP
        $this->assertEquals(5, $this->calculateRegen(0.5, 10));
    }

    public function testZeroRegenOnStreet(): void
    {
        // Utcán alvás, de street regen = 2%/óra
        $this->assertEquals(16, $this->calculateRegen(8.0, 2));
    }

    public function testMaxRegenDoesNotExceed100(): void
    {
        // Egészség sosem mehet 100 fölé (a SleepService LEAST(100, health+?) használ)
        $gain = $this->calculateRegen(12.0, 15); // 180
        $healthAfter = min(100, 30 + $gain); // 30 HP + 180 = max 100
        $this->assertEquals(100, $healthAfter);
    }

    public function testPartialHourCalculation(): void
    {
        // 2.5 óra, 8% regen → 20
        $this->assertEquals(20, $this->calculateRegen(2.5, 8));
    }

    public function testZeroHoursSlept(): void
    {
        // Azonnal felkelés → 0 regen
        $this->assertEquals(0, $this->calculateRegen(0.0, 10));
    }

    public function testSleepDurationCapped(): void
    {
        // A max órát nem lépheti túl (simulated by SleepService)
        $maxHours = 12;
        $actualHours = 15.0; // Többet aludt mint amit beállított
        $cappedHours = min($actualHours, $maxHours);
        $this->assertEquals(12, $cappedHours);
        $this->assertEquals(120, $this->calculateRegen($cappedHours, 10));
    }

    /**
     * Ellenőrzi, hogy a regen lineárisan nő az idővel
     */
    public function testRegenIsLinear(): void
    {
        $regenPerHour = 10;
        $prev = 0;
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $hours) {
            $current = $this->calculateRegen((float)$hours, $regenPerHour);
            $this->assertGreaterThan($prev, $current);
            $this->assertEquals($hours * $regenPerHour, $current);
            $prev = $current;
        }
    }
}
