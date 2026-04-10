<?php
declare(strict_types=1);

namespace Netmafia\Modules\Credits\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\ValueObjects\Credits;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Domain\Services\BaseEconomyService;
use InvalidArgumentException;
use RuntimeException;

/**
 * CreditService - Kredit műveletek kezelése teljes auditálással
 * 
 * Minden kredit művelet itt keresztül megy, így:
 * - Tranzakció biztonság garantált
 * - Minden mozgás loggolva van
 * - Integritás ellenőrzés automatikus
 */
class CreditService extends BaseEconomyService
{
    public function __construct(Connection $db, ?\Netmafia\Infrastructure\AuditLogger $auditLogger = null)
    {
        parent::__construct($db, $auditLogger);
    }

    protected function getAuditType(string $type): ?string
    {
        return match($type) {
            'admin_add', 'admin_remove' => \Netmafia\Infrastructure\AuditLogger::TYPE_ADMIN_ACTION,
            'transfer_out' => \Netmafia\Infrastructure\AuditLogger::TYPE_BANK_TRANSFER,
            default => null
        };
    }

    /**
     * User jelenlegi kredit egyenlege
     */
    public function getBalance(UserId $userId): Credits
    {
        $balance = $this->db->fetchOne(
            "SELECT credits FROM users WHERE id = ?",
            [$userId->id()]
        );

        return Credits::of((int) ($balance ?? 0));
    }

    /**
     * Kredit jóváírás (vásárlás, admin, referral)
     */
    public function addCredits(
        UserId $userId,
        Credits $amount,
        string $type,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adminUserId = null
    ): Credits {
        $this->validateType($type, ['purchase', 'admin_add', 'referral', 'refund', 'transfer_in', 'correction', 'market_escrow_in']);

        return $this->executeTransaction($userId, $amount->amount(), $type, $description, $referenceType, $referenceId, $adminUserId);
    }

    /**
     * Kredit levonás (költés)
     */
    public function spendCredits(
        UserId $userId,
        Credits $amount,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        string $type = 'spend'
    ): Credits {
        $allowed = ['spend', 'market_escrow_out'];
        $this->validateType($type, $allowed);
        
        // Negatív összegként adjuk át a levonáshoz
        return $this->executeTransaction(
            $userId, 
            -$amount->amount(), 
            $type, 
            $description, 
            $referenceType, 
            $referenceId
        );
    }

    /**
     * Admin levonás
     */
    public function removeCredits(
        UserId $userId,
        Credits $amount,
        string $description,
        int $adminUserId
    ): Credits {
        return $this->executeTransaction(
            $userId,
            -$amount->amount(),
            'admin_remove',
            $description,
            null,
            null,
            $adminUserId
        );
    }

    /**
     * Kredit átutalás userek között
     */
    public function transferCredits(
        UserId $fromUserId,
        UserId $toUserId,
        Credits $amount,
        string $description = 'Kredit átutalás'
    ): void {
        $this->db->beginTransaction();

        try {
            // Lock participants in consistent order to prevent deadlocks (Rule 2.7)
            $firstId = min($fromUserId->id(), $toUserId->id());
            $secondId = max($fromUserId->id(), $toUserId->id());
            $this->db->executeStatement("SELECT id FROM users WHERE id IN (?, ?) FOR UPDATE", [$firstId, $secondId]);

            // Levonás a küldőtől
            $this->executeTransaction(
                $fromUserId,
                -$amount->amount(),
                'transfer_out',
                $description . ' → User #' . $toUserId->id(),
                'user',
                $toUserId->id()
            );

            // Jóváírás a címzettnek
            $this->executeTransaction(
                $toUserId,
                $amount->amount(),
                'transfer_in',
                $description . ' ← User #' . $fromUserId->id(),
                'user',
                $fromUserId->id()
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function executeTransaction(
        UserId $userId,
        int $amount,
        string $type,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adminUserId = null
    ): Credits {
        return $this->executeBaseTransaction(
            $userId, $amount, $type, $description, $referenceType, $referenceId, $adminUserId
        );
    }

    protected function getCurrencyColumn(): string { return 'credits'; }
    protected function getTransactionTable(): string { return 'credit_transactions'; }
    protected function formatResult(int $balance): mixed { return Credits::of($balance); }
    protected function getLogSource(string $type): string { return "PHP: CreditService::" . $type; }
    protected function throwInsufficientFundsException(int $currentBalance, int $amount): void {
        throw new InvalidArgumentException(
            sprintf('Nincs elég kredit! Egyenleg: %d, Szükséges: %d', $currentBalance, $amount)
        );
    }
    protected function throwUserNotFoundException(int $userId): void {
        throw new RuntimeException("Felhasználó nem található (ID: {$userId})");
    }

    /**
     * Típus validálás
     */
    private function validateType(string $type, array $allowedTypes): void
    {
        if (!in_array($type, $allowedTypes, true)) {
            throw new InvalidArgumentException(
                sprintf('Érvénytelen tranzakció típus: %s. Engedélyezett: %s', $type, implode(', ', $allowedTypes))
            );
        }
    }

    /**
     * Hibás tranzakciók lekérdezése (admin riasztáshoz)
     * 
     * @return array<int, array>
     */
    public function getInvalidTransactions(int $limit = 100): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT ct.*, u.username 
             FROM credit_transactions ct
             LEFT JOIN users u ON u.id = ct.user_id
             WHERE ct.is_valid = FALSE
             ORDER BY ct.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * User tranzakció történet
     * 
     * @return array<int, array>
     */
    public function getTransactionHistory(UserId $userId, int $limit = 50): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, user_id, amount, type, description, balance_after, reference_type, reference_id, is_valid, created_at FROM credit_transactions 
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$userId->id(), $limit]
        );
    }

    /**
     * Napi statisztika (admin dashboard)
     * 
     * @return array<string, int>
     */
    public function getDailyStats(): array
    {
        $today = date('Y-m-d');
        
        $stats = $this->db->fetchAssociative(
            "SELECT 
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_added,
                SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_spent,
                COUNT(*) as transaction_count,
                SUM(CASE WHEN is_valid = FALSE THEN 1 ELSE 0 END) as invalid_count
             FROM credit_transactions
             WHERE DATE(created_at) = ?",
            [$today]
        );

        return [
            'total_added' => (int) ($stats['total_added'] ?? 0),
            'total_spent' => (int) ($stats['total_spent'] ?? 0),
            'transaction_count' => (int) ($stats['transaction_count'] ?? 0),
            'invalid_count' => (int) ($stats['invalid_count'] ?? 0),
        ];
    }

    /**
     * Integritás ellenőrzés - egy user egyenlege megegyezik-e a tranzakciók alapján számított értékkel?
     * 
     * @return array{valid: bool, expected: int, actual: int, difference: int}
     */
    public function verifyUserIntegrity(UserId $userId): array
    {
        // 1. Jelenlegi egyenleg a users táblából
        $fetchResult = $this->db->fetchOne(
            "SELECT credits FROM users WHERE id = ?",
            [$userId->id()]
        );
        if ($fetchResult === false) {
            throw new \RuntimeException("Felhasználó (vagy egyenleg) nem található!");
        }
        $actualBalance = (int) $fetchResult;

        // 2. Utolsó tranzakció balance_after értéke
        $lastTransaction = $this->db->fetchAssociative(
            "SELECT balance_after FROM credit_transactions 
             WHERE user_id = ? 
             ORDER BY created_at DESC, id DESC 
             LIMIT 1",
            [$userId->id()]
        );

        // Ha nincs tranzakció, az egyenleg 0 kéne legyen
        $expectedBalance = $lastTransaction ? (int) $lastTransaction['balance_after'] : 0;

        $isValid = ($actualBalance === $expectedBalance);
        $difference = $actualBalance - $expectedBalance;

        // Ha eltérés van, loggoljuk
        if (!$isValid) {
            $this->logIntegrityViolation($userId, $expectedBalance, $actualBalance, $difference);
        }

        return [
            'valid' => $isValid,
            'expected' => $expectedBalance,
            'actual' => $actualBalance,
            'difference' => $difference,
        ];
    }

    /**
     * Összes user integritás ellenőrzése (cron job-hoz)
     * 
     * [2026-02-15] FIX: N+1 query javítás – korábban minden userhez 2 SQL futott.
     * Most egyetlen batch query-vel kérjük le az utolsó tranzakciós egyenlegeket.
     * 
     * @return array{checked: int, valid: int, invalid: int, violations: array}
     */
    public function verifyAllUsersIntegrity(): array
    {
        $result = [
            'checked' => 0,
            'valid' => 0,
            'invalid' => 0,
            'violations' => [],
        ];

        // [2026-02-15] FIX: Egyetlen batch query – users + utolsó kredit tranzakció JOIN-olva
        $users = $this->db->fetchAllAssociative(
            "SELECT u.id, u.username, u.credits as actual_balance,
                    (SELECT ct.balance_after 
                     FROM credit_transactions ct 
                     WHERE ct.user_id = u.id 
                     ORDER BY ct.created_at DESC, ct.id DESC 
                     LIMIT 1) as last_tx_balance
             FROM users u
             WHERE EXISTS (SELECT 1 FROM credit_transactions ct2 WHERE ct2.user_id = u.id)
                OR u.credits != 0"
        );

        foreach ($users as $user) {
            $result['checked']++;
            
            $actualBalance = (int) $user['actual_balance'];
            // Ha nincs tranzakció, az aktuális egyenleg a helyes
            $expectedBalance = $user['last_tx_balance'] !== null 
                ? (int) $user['last_tx_balance'] 
                : $actualBalance;
            
            if ($actualBalance === $expectedBalance) {
                $result['valid']++;
            } else {
                $result['invalid']++;
                $difference = $actualBalance - $expectedBalance;
                
                // Integritási hiba logolása
                $this->logIntegrityViolation(
                    UserId::of((int) $user['id']), 
                    $expectedBalance, 
                    $actualBalance, 
                    $difference
                );
                
                $result['violations'][] = [
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'expected' => $expectedBalance,
                    'actual' => $actualBalance,
                    'difference' => $difference,
                ];
            }
        }

        return $result;
    }

    /**
     * Integritási hiba logolása
     */
    private function logIntegrityViolation(UserId $userId, int $expected, int $actual, int $difference): void
    {
        try {
            $this->db->insert('credit_integrity_violations', [
                'user_id' => $userId->id(),
                'expected_balance' => $expected,
                'actual_balance' => $actual,
                'difference' => $difference,
                'detected_at' => gmdate('Y-m-d H:i:s'),
                'resolved' => false,
            ]);
        } catch (\Throwable $e) {
            // Ha nincs tábla vagy más hiba, ne haljon meg
            error_log("Credit integrity violation log error: " . $e->getMessage());
        }
    }
}

