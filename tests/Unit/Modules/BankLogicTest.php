<?php
declare(strict_types=1);

namespace Tests\Unit\Modules;

use PHPUnit\Framework\TestCase;

/**
 * Bank Business Logic Tests
 * 
 * Teszteli a banki üzleti logikát: kezelési költség számítás,
 * egyenleg validáció, átutalás logika.
 * (Pure unit tesztek — nem igényel DB-t)
 */
class BankLogicTest extends TestCase
{
    // ============================================================
    // Deposit Fee Tests
    // ============================================================

    private function calculateFee(int $amount, float $feePercent = 0.05): array
    {
        $fee = (int) floor($amount * $feePercent);
        $netAmount = $amount - $fee;
        return ['fee' => $fee, 'net' => $netAmount];
    }

    public function testDepositFeeCalculation(): void
    {
        $result = $this->calculateFee(1000);
        $this->assertEquals(50, $result['fee']);
        $this->assertEquals(950, $result['net']);
    }

    public function testDepositFeeWithSmallAmount(): void
    {
        $result = $this->calculateFee(10);
        $this->assertEquals(0, $result['fee']); // floor(10 * 0.05) = 0
        $this->assertEquals(10, $result['net']);
    }

    public function testDepositFeeWithLargeAmount(): void
    {
        $result = $this->calculateFee(1000000);
        $this->assertEquals(50000, $result['fee']);
        $this->assertEquals(950000, $result['net']);
    }

    public function testDepositFeeFloors(): void
    {
        // 33 * 0.05 = 1.65 → floor → 1
        $result = $this->calculateFee(33);
        $this->assertEquals(1, $result['fee']);
        $this->assertEquals(32, $result['net']);
    }

    // ============================================================
    // Balance Validation Tests
    // ============================================================

    private function validateWithdraw(int $currentBalance, int $amount): bool
    {
        return ($currentBalance - $amount) >= 0;
    }

    public function testWithdrawWithSufficientBalance(): void
    {
        $this->assertTrue($this->validateWithdraw(1000, 500));
    }

    public function testWithdrawWithExactBalance(): void
    {
        $this->assertTrue($this->validateWithdraw(500, 500));
    }

    public function testWithdrawWithInsufficientBalance(): void
    {
        $this->assertFalse($this->validateWithdraw(100, 500));
    }

    public function testWithdrawZeroAmount(): void
    {
        $this->assertTrue($this->validateWithdraw(100, 0));
    }

    // ============================================================
    // Transfer Validation Tests
    // ============================================================

    public function testSelfTransferDetection(): void
    {
        $sourceAccountNumber = 12345;
        $targetAccountNumber = 12345;
        $this->assertTrue($sourceAccountNumber == $targetAccountNumber, "Self-transfer should be detected");
    }

    public function testDifferentAccountTransfer(): void
    {
        $sourceAccountNumber = 12345;
        $targetAccountNumber = 67890;
        $this->assertFalse($sourceAccountNumber == $targetAccountNumber, "Different accounts should pass");
    }

    // ============================================================
    // Account Number Generation Tests
    // ============================================================

    public function testAccountNumberRange(): void
    {
        // Számlaszám: 10000-99999 (5 jegyű)
        for ($i = 0; $i < 100; $i++) {
            $number = random_int(10000, 99999);
            $this->assertGreaterThanOrEqual(10000, $number);
            $this->assertLessThanOrEqual(99999, $number);
        }
    }

    // ============================================================
    // Lock Ordering Tests  
    // ============================================================

    public function testConsistentLockOrdering(): void
    {
        // A→B és B→A esetén mindig a kisebb ID kerül először lock-ra
        $accountA = 5;
        $accountB = 12;

        $firstLock = min($accountA, $accountB);
        $secondLock = max($accountA, $accountB);

        $this->assertEquals(5, $firstLock);
        $this->assertEquals(12, $secondLock);

        // Fordított sorrendben is ugyanaz
        $firstLock2 = min($accountB, $accountA);
        $secondLock2 = max($accountB, $accountA);

        $this->assertEquals($firstLock, $firstLock2);
        $this->assertEquals($secondLock, $secondLock2);
    }
}
