<?php
declare(strict_types=1);

namespace Netmafia\Shared\Domain\Services;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\ValueObjects\UserId;

/**
 * BaseEconomyService
 * 
 * Közös ősosztály a MoneyService és a CreditService számára.
 * A tranzakciókezelést és loggolást (DRY elv) egyszer írjuk le itt.
 */
abstract class BaseEconomyService
{
    protected Connection $db;
    protected ?\Netmafia\Infrastructure\AuditLogger $auditLogger;

    public function __construct(Connection $db, ?\Netmafia\Infrastructure\AuditLogger $auditLogger = null)
    {
        $this->db = $db;
        $this->auditLogger = $auditLogger;
    }

    abstract protected function getCurrencyColumn(): string;
    abstract protected function getTransactionTable(): string;
    abstract protected function formatResult(int $balance): mixed;
    abstract protected function getLogSource(string $type): string;
    abstract protected function throwInsufficientFundsException(int $currentBalance, int $amount): void;
    abstract protected function throwUserNotFoundException(int $userId): void;
    
    /**
     * Meghatározza, hogy egy adott tranzakció típushoz tartozik-e automatikus Audit Log bejegyzés.
     * Ha null-t ad vissza, nem készül audit bejegyzés.
     */
    protected function getAuditType(string $type): ?string
    {
        return null;
    }

    /**
     * Közös tranzakciókezelő logika beépített FOR UPDATE lockkal és nested tranzakció védelemmel
     * [2026-02-28] Kiterjesztve automatikus AuditLogger hívással.
     */
    protected function executeBaseTransaction(
        UserId $userId,
        int $amount,
        string $type,
        string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adminUserId = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): mixed {
        $isNestedTransaction = $this->db->getTransactionNestingLevel() > 0;
        
        if (!$isNestedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->executeStatement("SET @audit_source = ?", [$this->getLogSource($type)]);

            $column = $this->getCurrencyColumn();
            
            $result = $this->db->fetchOne(
                "SELECT {$column} FROM users WHERE id = ? FOR UPDATE",
                [$userId->id()]
            );

            if ($result === false) {
                $this->throwUserNotFoundException($userId->id());
            }

            $currentBalance = (int) $result;
            $newBalance = $currentBalance + $amount;

            if ($newBalance < 0) {
                $this->throwInsufficientFundsException($currentBalance, abs($amount));
            }

            $this->db->executeStatement(
                "UPDATE users SET {$column} = ? WHERE id = ?",
                [$newBalance, $userId->id()]
            );

            $this->db->insert($this->getTransactionTable(), [
                'user_id' => $userId->id(),
                'amount' => $amount,
                'balance_before' => $currentBalance,
                'balance_after' => $newBalance,
                'type' => $type,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'admin_user_id' => $adminUserId,
                'ip_address' => $ip,
                'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
            ]);

            // [2026-02-28] Automatikus Audit Log bejegyzés, ha van hozzárendelt típus
            $auditType = $this->getAuditType($type);
            if ($auditType && $this->auditLogger) {
                $this->auditLogger->log($auditType, $userId->id(), [
                    'amount' => $amount,
                    'currency' => $column,
                    'type' => $type,
                    'description' => $description,
                    'ref_type' => $referenceType,
                    'ref_id' => $referenceId,
                    'balance_after' => $newBalance
                ], $ip);
            }

            if (!$isNestedTransaction) {
                $this->db->commit();
            }

            return $this->formatResult($newBalance);

        } catch (\Throwable $e) {
            if (!$isNestedTransaction && $this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
