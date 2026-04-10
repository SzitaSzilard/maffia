<?php
declare(strict_types=1);

namespace Netmafia\Modules\Money\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Domain\Services\BaseEconomyService;

/**
 * MoneyService - Pénz műveletek kezelése teljes auditálással
 * 
 * Hasonló a CreditService-hez:
 * - Tranzakció biztonság garantált
 * - Minden mozgás loggolva van
 * - Integritás ellenőrzés
 */
class MoneyService extends BaseEconomyService
{
    public function __construct(Connection $db, ?\Netmafia\Infrastructure\AuditLogger $auditLogger = null)
    {
        parent::__construct($db, $auditLogger);
    }

    /**
     * AuditLog hozzárendelés a tranzakció típusokhoz
     * 
     * CSAK a biztonsági/admin eseményeket externalizáljuk az audit_logs-ba.
     * A pénzügyi tranzakciók (hospital_heal, purchase, restaurant_eat) már
     * benne vannak a money_transactions-ban — audit_logs-ba duplikálni felesleges.
     */
    protected function getAuditType(string $type): ?string
    {
        return match($type) {
            'bank_withdraw' => \Netmafia\Infrastructure\AuditLogger::TYPE_BANK_WITHDRAW,
            'bank_deposit'  => \Netmafia\Infrastructure\AuditLogger::TYPE_BANK_DEPOSIT,
            'transfer_out'  => \Netmafia\Infrastructure\AuditLogger::TYPE_BANK_TRANSFER,
            'admin_add', 'admin_remove' => \Netmafia\Infrastructure\AuditLogger::TYPE_ADMIN_ACTION,
            'robbery', 'robbery_victim' => \Netmafia\Infrastructure\AuditLogger::TYPE_SUSPICIOUS,
            'casino_win', 'casino_loss' => \Netmafia\Infrastructure\AuditLogger::TYPE_SUSPICIOUS,
            default => null  // Pénzügyi tranzakciók (hospital_heal, purchase stb.) már money_transactions-ban vannak
        };
    }

    /**
     * User jelenlegi pénz egyenlege
     */
    public function getBalance(UserId $userId): int
    {
        $balance = $this->db->fetchOne(
            "SELECT money FROM users WHERE id = ?",
            [$userId->id()]
        );

        return (int) ($balance ?? 0);
    }

    /**
     * Pénz jóváírás (munka, eladás, admin)
     */
    public function addMoney(
        UserId $userId,
        int $amount,
        string $type,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adminUserId = null
    ): int {
        if ($amount < 0) {
            throw new InvalidInputException("Csak pozitív összeget lehet hozzáadni! (Használd a removeMoney-t levonáshoz)");
        }

        if ($amount > \Netmafia\Modules\Money\MoneyConfig::MAX_TRANSACTION_AMOUNT) { // Integer Overflow védelem
            throw new InvalidInputException("Túl nagy összeg! A rendszer maximum " . number_format(\Netmafia\Modules\Money\MoneyConfig::MAX_TRANSACTION_AMOUNT) . " tud kezelni tranzakciónként.");
        }

        $this->validateType($type, [
            'work', 'sell', 'transfer_in', 'admin_add', 
            'casino_win', 'robbery', 'bank_withdraw', 'refund',
            'building_income' // Épület bevétel
        ]);

        return $this->executeTransaction(
            $userId, $amount, $type, $description, 
            $referenceType, $referenceId, $adminUserId
        );
    }

    /**
     * Pénz levonás (vásárlás, költés)
     */
    public function spendMoney(
        UserId $userId,
        int $amount,
        string $type,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null
    ): int {
        $this->validateType($type, [
            'purchase', 'transfer_out', 'casino_loss', 
            'bank_deposit', 'robbery_victim', 'fine', 'spend',
            'building_usage', 'restaurant_eat', 'hospital_heal' // Kórház gyógyítás
        ]);

        return $this->executeTransaction(
            $userId, -$amount, $type, $description,
            $referenceType, $referenceId
        );
    }

    /**
     * Admin levonás
     */
    public function removeMoney(
        UserId $userId,
        int $amount,
        string $description,
        int $adminUserId
    ): int {
        return $this->executeTransaction(
            $userId, -$amount, 'admin_remove', $description,
            null, null, $adminUserId
        );
    }

    /**
     * Pénz átutalás userek között
     */
    public function transferMoney(
        UserId $fromUserId,
        UserId $toUserId,
        int $amount,
        string $description = 'Pénz átutalás'
    ): void {
        if ($amount <= 0) {
            throw new InvalidInputException('Az átutalt összeg csak pozitív szám lehet!');
        }

        if ($amount > \Netmafia\Modules\Money\MoneyConfig::MAX_TRANSACTION_AMOUNT) {
            throw new InvalidInputException('Túl nagy összeg! A rendszer maximum ' . number_format(\Netmafia\Modules\Money\MoneyConfig::MAX_TRANSACTION_AMOUNT) . ' forintot tud kezelni tranzakciónként.');
        }

        if ($fromUserId->id() === $toUserId->id()) {
            throw new InvalidInputException('Nem utalhatsz saját magadnak!');
        }

        $this->db->beginTransaction();

        try {
            // Lock participants in consistent order to prevent deadlocks (Rule 2.7)
            $firstId = min($fromUserId->id(), $toUserId->id());
            $secondId = max($fromUserId->id(), $toUserId->id());
            $this->db->executeStatement("SELECT id FROM users WHERE id IN (?, ?) FOR UPDATE", [$firstId, $secondId]);

            // Levonás a küldőtől
            $this->executeTransaction(
                $fromUserId, -$amount, 'transfer_out',
                $description . ' → User #' . $toUserId->id(),
                'user', $toUserId->id()
            );

            // Jóváírás a címzettnek
            $this->executeTransaction(
                $toUserId, $amount, 'transfer_in',
                $description . ' ← User #' . $fromUserId->id(),
                'user', $fromUserId->id()
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
    ): int {
        return $this->executeBaseTransaction(
            $userId, $amount, $type, $description, $referenceType, $referenceId, $adminUserId
        );
    }

    protected function getCurrencyColumn(): string { return 'money'; }
    protected function getTransactionTable(): string { return 'money_transactions'; }
    protected function formatResult(int $balance): mixed { return $balance; }
    protected function getLogSource(string $type): string { return "PHP: MoneyService::" . $type; }
    protected function throwInsufficientFundsException(int $currentBalance, int $amount): void {
        throw new InsufficientBalanceException($currentBalance, $amount);
    }
    protected function throwUserNotFoundException(int $userId): void {
        throw new UserNotFoundException($userId);
    }

    /**
     * Típus validálás
     */
    private function validateType(string $type, array $allowedTypes): void
    {
        if (!in_array($type, $allowedTypes, true)) {
            throw new InvalidTransactionTypeException($type, $allowedTypes);
        }
    }

    /**
     * User tranzakció történet
     */
    public function getTransactionHistory(UserId $userId, int $limit = 50): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, user_id, amount, type, description, balance_after, reference_type, reference_id, created_at FROM money_transactions 
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$userId->id(), $limit]
        );
    }

    /**
     * Integritás ellenőrzés - user egyenlege megegyezik-e a tranzakciók alapján számított értékkel?
     */
    public function verifyUserIntegrity(UserId $userId): array
    {
        // 1. Jelenlegi egyenleg a users táblából
        $fetchResult = $this->db->fetchOne(
            "SELECT money FROM users WHERE id = ?",
            [$userId->id()]
        );
        if ($fetchResult === false) {
            throw new \Netmafia\Shared\Exceptions\GameException("Felhasználó (vagy egyenleg) nem található!");
        }
        $actualBalance = (int) $fetchResult;
        // 2. Utolsó tranzakció balance_after értéke
        $lastTransaction = $this->db->fetchAssociative(
            "SELECT balance_after FROM money_transactions 
             WHERE user_id = ? 
             ORDER BY created_at DESC, id DESC 
             LIMIT 1",
            [$userId->id()]
        );

        // FIX: Ha nincs tranzakció, a user aktuális egyenlege a "helyes"
        // Nem feltételezhetünk hard-coded kezdőértéket (120000)!
        // Ha nincs tranzakció → expected = actual (valid by default)
        $expectedBalance = $lastTransaction 
            ? (int) $lastTransaction['balance_after'] 
            : $actualBalance; // FIX: actualBalance, nem 120000!

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
     * Összes user integritás ellenőrzése
     * 
     * [2026-02-15] FIX: N+1 query javítás – korábban minden userhez 2 SQL futott.
     * Most egyetlen batch query-vel kérjük le az utolsó tranzakciós egyenlegeket
     * és hasonlítjuk össze a users tábla aktuális egyenlegeivel.
     */
    public function verifyAllUsersIntegrity(): array
    {
        $result = [
            'checked' => 0,
            'valid' => 0,
            'invalid' => 0,
            'violations' => [],
        ];

        // [2026-02-15] FIX: Egyetlen batch query – users + utolsó tranzakció JOIN-olva
        $users = $this->db->fetchAllAssociative(
            "SELECT u.id, u.username, u.money as actual_balance,
                    (SELECT mt.balance_after 
                     FROM money_transactions mt 
                     WHERE mt.user_id = u.id 
                     ORDER BY mt.created_at DESC, mt.id DESC 
                     LIMIT 1) as last_tx_balance
             FROM users u"
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
            $this->db->insert('money_integrity_violations', [
                'user_id' => $userId->id(),
                'expected_balance' => $expected,
                'actual_balance' => $actual,
                'difference' => $difference,
                'detected_at' => gmdate('Y-m-d H:i:s'),
                'resolved' => false,
            ]);
        } catch (\Throwable $e) {
            error_log("Money integrity violation log error: " . $e->getMessage());
        }
    }

    /**
     * Napi statisztika
     */
    public function getDailyStats(): array
    {
        $today = date('Y-m-d');
        
        $stats = $this->db->fetchAssociative(
            "SELECT 
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_added,
                SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_spent,
                COUNT(*) as transaction_count
             FROM money_transactions
             WHERE DATE(created_at) = ?",
            [$today]
        );

        return [
            'total_added' => (int) ($stats['total_added'] ?? 0),
            'total_spent' => (int) ($stats['total_spent'] ?? 0),
            'transaction_count' => (int) ($stats['transaction_count'] ?? 0),
        ];
    }
}
