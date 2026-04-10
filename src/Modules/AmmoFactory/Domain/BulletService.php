<?php
declare(strict_types=1);

namespace Netmafia\Modules\AmmoFactory\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;
use Netmafia\Shared\Domain\ValueObjects\UserId;

/**
 * BulletService — Töltény mozgás kezelése teljes ledger auditálással
 *
 * Minden töltény mozgás itt keresztül megy, így:
 * - Tranzakció biztonság garantált (FOR UPDATE)
 * - Minden mozgás naplózva van (bullet_transactions)
 * - DB CHECK CONSTRAINT garantálja balance_before + amount = balance_after
 * - Integritás ellenőrzés bármikor futtatható
 */
class BulletService
{
    // Engedélyezett típusok
    private const ALLOWED_ADD_TYPES  = ['ammo_factory', 'postal_receive', 'market_buy', 'admin_add', 'refund', 'market_escrow_in'];
    private const ALLOWED_USE_TYPES  = ['combat_use', 'postal_send', 'market_sell', 'admin_remove', 'market_escrow_out'];

    private Connection $db;
    private ?AuditLogger $auditLogger;

    public function __construct(Connection $db, ?AuditLogger $auditLogger = null)
    {
        $this->db          = $db;
        $this->auditLogger = $auditLogger;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ÍRÁS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Töltény jóváírás
     *
     * @param string $type  Pl. 'ammo_factory', 'market_buy', 'postal_receive'
     * @return int  Új egyenleg
     */
    public function addBullets(
        UserId  $userId,
        int     $amount,
        string  $type,
        string  $description,
        ?string $referenceType = null,
        ?int    $referenceId   = null
    ): int {
        if ($amount <= 0) {
            throw new InvalidInputException("Csak pozitív értéket lehet hozzáadni.");
        }

        $this->validateType($type, self::ALLOWED_ADD_TYPES);

        return $this->executeTransaction($userId, $amount, $type, $description, $referenceType, $referenceId);
    }

    /**
     * Töltény levonás
     *
     * @param string $type  Pl. 'combat_use', 'postal_send', 'market_sell'
     * @return int  Új egyenleg
     */
    public function useBullets(
        UserId  $userId,
        int     $amount,
        string  $type,
        string  $description,
        ?string $referenceType = null,
        ?int    $referenceId   = null
    ): int {
        if ($amount <= 0) {
            throw new InvalidInputException("Csak pozitív értéket lehet levonni.");
        }

        $this->validateType($type, self::ALLOWED_USE_TYPES);

        return $this->executeTransaction($userId, -$amount, $type, $description, $referenceType, $referenceId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  LEKÉRDEZÉS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Aktuális töltényegyenleg
     */
    public function getBalance(UserId $userId): int
    {
        $result = $this->db->fetchOne(
            "SELECT bullets FROM users WHERE id = ?",
            [$userId->id()]
        );

        return (int) ($result ?? 0);
    }

    /**
     * Töltény tranzakció napló
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTransactionHistory(UserId $userId, int $limit = 50): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, user_id, amount, type, description, balance_before, balance_after,
                    reference_type, reference_id, created_at
             FROM bullet_transactions
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ?",
            [$userId->id(), $limit]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  INTEGRITÁS ELLENŐRZÉS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Egyedi user integritás ellenőrzés
     *
     * Visszatér:
     *  - valid: bool
     *  - expected: int (utolsó tranzakció balance_after)
     *  - actual: int  (users.bullets)
     *  - difference: int
     */
    public function verifyUserIntegrity(UserId $userId): array
    {
        $actualBalance = $this->getBalance($userId);

        $lastTx = $this->db->fetchAssociative(
            "SELECT balance_after FROM bullet_transactions
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            [$userId->id()]
        );

        // Ha még nincs egyetlen tranzakció sem → az aktuális egyenleg a helyes alap
        $expectedBalance = $lastTx !== false && $lastTx !== null
            ? (int) $lastTx['balance_after']
            : $actualBalance;

        $isValid    = ($actualBalance === $expectedBalance);
        $difference = $actualBalance - $expectedBalance;

        if (!$isValid) {
            $this->logIntegrityViolation($userId, $expectedBalance, $actualBalance, $difference);

            if ($this->auditLogger !== null) {
                $this->auditLogger->log(AuditLogger::TYPE_SUSPICIOUS, $userId->id(), [
                    'currency'   => 'bullets',
                    'expected'   => $expectedBalance,
                    'actual'     => $actualBalance,
                    'difference' => $difference,
                ]);
            }
        }

        return [
            'valid'      => $isValid,
            'expected'   => $expectedBalance,
            'actual'     => $actualBalance,
            'difference' => $difference,
        ];
    }

    /**
     * Batch integritás ellenőrzés — összes user
     *
     * @return array{checked: int, valid: int, invalid: int, violations: array}
     */
    public function verifyAllUsersIntegrity(): array
    {
        $result = ['checked' => 0, 'valid' => 0, 'invalid' => 0, 'violations' => []];

        $users = $this->db->fetchAllAssociative(
            "SELECT u.id, u.username, u.bullets AS actual_balance,
                    (SELECT bt.balance_after
                     FROM bullet_transactions bt
                     WHERE bt.user_id = u.id
                     ORDER BY bt.created_at DESC, bt.id DESC
                     LIMIT 1) AS last_tx_balance
             FROM users u"
        );

        foreach ($users as $user) {
            $result['checked']++;
            $actual   = (int) $user['actual_balance'];
            $expected = ($user['last_tx_balance'] !== null) ? (int) $user['last_tx_balance'] : $actual;

            if ($actual === $expected) {
                $result['valid']++;
            } else {
                $result['invalid']++;
                $diff = $actual - $expected;
                $uid  = UserId::of((int) $user['id']);

                $this->logIntegrityViolation($uid, $expected, $actual, $diff);

                $result['violations'][] = [
                    'user_id'  => $user['id'],
                    'username' => $user['username'],
                    'expected' => $expected,
                    'actual'   => $actual,
                    'difference' => $diff,
                ];
            }
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  PRIVÁT SEGÉDMETÓDUSOK
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ledger tranzakció futtatása
     * - FOR UPDATE pesszimista lock
     * - balance_before + amount = balance_after (DB CHECK CONSTRAINT is védi)
     */
    private function executeTransaction(
        UserId  $userId,
        int     $amount,
        string  $type,
        string  $description,
        ?string $referenceType,
        ?int    $referenceId
    ): int {
        $isNested = $this->db->getTransactionNestingLevel() > 0;

        if (!$isNested) {
            $this->db->beginTransaction();
        }

        try {
            $__fetchResult = $this->db->fetchOne(
                "SELECT bullets FROM users WHERE id = ? FOR UPDATE",
                [$userId->id()]
            );
            if ($__fetchResult === false) {
                throw new GameException('Felhasználó (vagy egyenleg) nem található!');
            }
            $currentBalance = (int) $__fetchResult;

            $newBalance = $currentBalance + $amount;

            if ($newBalance < 0) {
                throw new GameException(
                    sprintf("Nincs elég töltényed! Egyenleg: %d, Szükséges: %d", $currentBalance, abs($amount))
                );
            }

            // Egyenleg frissítés
            // [NULL-SAFE] @audit_source jelzi a triggernek hogy PHP kezeli (nem bypass)
            try {
                $this->db->executeStatement("SET @audit_source = ?", ['BulletService::' . $type]);
                $this->db->executeStatement(
                    "UPDATE users SET bullets = ? WHERE id = ?",
                    [$newBalance, $userId->id()]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            // Ledger bejegyzés — a DB CHECK CONSTRAINT garantálja: before + amount = after
            $this->db->insert('bullet_transactions', [
                'user_id'        => $userId->id(),
                'amount'         => $amount,
                'balance_before' => $currentBalance,
                'balance_after'  => $newBalance,
                'type'           => $type,
                'description'    => substr($description, 0, 255),
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ]);

            if (!$isNested) {
                $this->db->commit();
            }

            return $newBalance;

        } catch (\Throwable $e) {
            if (!$isNested && $this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Típus validáció whitelist alapon
     */
    private function validateType(string $type, array $allowed): void
    {
        if (!in_array($type, $allowed, true)) {
            throw new InvalidInputException(
                sprintf("Érvénytelen töltény tranzakció típus: '%s'. Engedélyezett: %s", $type, implode(', ', $allowed))
            );
        }
    }

    /**
     * Integritási hiba naplózása
     */
    private function logIntegrityViolation(UserId $userId, int $expected, int $actual, int $difference): void
    {
        try {
            $this->db->insert('bullet_integrity_violations', [
                'user_id'          => $userId->id(),
                'expected_balance' => $expected,
                'actual_balance'   => $actual,
                'difference'       => $difference,
                'detected_at'      => gmdate('Y-m-d H:i:s'),
                'resolved'         => 0,
            ]);
        } catch (\Throwable $e) {
            error_log("[BulletService] integrity violation log error: " . $e->getMessage());
        }
    }
}
