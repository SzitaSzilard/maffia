<?php
declare(strict_types=1);

namespace Netmafia\Modules\Bank\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Notifications\Domain\NotificationService;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Shared\Domain\ValueObjects\UserId;

/**
 * BankService - Banki műveletek kezelése
 */
class BankService
{
    private Connection $db;
    private MoneyService $moneyService;
    private NotificationService $notificationService;
    private AuditLogger $auditLogger;

    public function __construct(Connection $db, MoneyService $moneyService, NotificationService $notificationService, AuditLogger $auditLogger)
    {
        $this->db = $db;
        $this->moneyService = $moneyService;
        $this->notificationService = $notificationService;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Számla meglétének ellenőrzése
     */
    public function hasAccount(int $userId): bool
    {
        return (bool) $this->db->fetchOne("SELECT 1 FROM bank_accounts WHERE user_id = ?", [$userId]);
    }

    /**
     * Számla nyitása (5 számjegyű egyedi számlaszám)
     * 
     * [2025-12-29 14:12:50] Javítás: Exception handling UNIQUE constraint-re
     * Korábban ha 2 user egyszerre nyitott számlát és ugyanazt a random számot
     * kapták, akkor PDO exception-t dobott. Most ezt elkapjuk és szebb
     * hibaüzenetet adunk vissza.
     */
    public function openAccount(UserId $userId): int
    {
        if ($this->hasAccount($userId->id())) {
            throw new GameException("Már van bankszámlád!");
        }

        $accountNumber = 0;
        $tries = 0;
        $maxRetries = 3; // [2025-12-29 14:12:50] Retry mechanizmus UNIQUE collision esetén
        
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $tries = 0;
            do {
                $accountNumber = random_int(\Netmafia\Modules\Bank\BankConfig::ACCOUNT_NUMBER_MIN, \Netmafia\Modules\Bank\BankConfig::ACCOUNT_NUMBER_MAX);
                $exists = $this->db->fetchOne("SELECT 1 FROM bank_accounts WHERE account_number = ?", [$accountNumber]);
                $tries++;
            } while ($exists && $tries < \Netmafia\Modules\Bank\BankConfig::MAX_GENERATION_ATTEMPTS);

            if ($exists) {
                throw new GameException("Nem sikerült egyedi számlaszámot generálni.");
            }

            try {
                // [2025-12-29 14:12:50] Try-catch UNIQUE constraint violation-re
                $this->db->insert('bank_accounts', [
                    'user_id' => $userId->id(),
                    'account_number' => $accountNumber,
                    'balance' => 0,
                    'created_at' => gmdate('Y-m-d H:i:s')
                ]);

                return $accountNumber; // Sikeres beszúrás
                
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                // Race condition: másik user ugyanazt a számot kapta közben
                // Újrapróbálkozás új számlaszámmal
                if ($attempt === $maxRetries - 1) {
                    // Utolsó próbálkozás volt, feladjuk
                    throw new GameException(
                        "Számlaszám generálás sikertelen több próbálkozás után. Kérlek próbáld újra!"
                    );
                }
                // Folytatjuk a következő iterációval (új random szám)
            }
        }
        
        throw new GameException("Váratlan hiba a számla nyitása során.");
    }

    /**
     * Számlaadatok lekérdezése
     */
    public function getAccount(int $userId): ?array
    {
        $account = $this->db->fetchAssociative("SELECT id, user_id, account_number, balance, created_at FROM bank_accounts WHERE user_id = ?", [$userId]);
        return $account ?: null;
    }

    /**
     * Számla keresés számlaszám alapján
     */
    public function findAccountByNumber(int $accountNumber): ?array
    {
        $account = $this->db->fetchAssociative("SELECT id, user_id, account_number, balance, created_at FROM bank_accounts WHERE account_number = ?", [$accountNumber]);
        return $account ?: null;
    }


    /**
     * Pénz befizetés (Wallet -> Bank)
     * 5% kezelési költség
     */
    public function deposit(UserId $userId, int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidInputException("Csak pozitív összeget lehet befizetni.");
        }

        $this->db->beginTransaction();
        try {
            // [FIX] Deadlock védelem: Mindig a users táblát lockoljuk először!
            $this->db->executeQuery("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId->id()]);

            // 1. Pénz levonás (MoneyService)
            $fee = (int) ceil($amount * \Netmafia\Modules\Bank\BankConfig::DEPOSIT_FEE_PERCENT);
            $netAmount = $amount - $fee;

            $this->moneyService->spendMoney($userId, $amount, 'bank_deposit', "Banki befizetés ($amount)", 'bank', null);

            // 2. Bank jóváírás (Szabálypont 9.2: Központi Ledger hívás)
            $accountId = $this->getAccountId($userId->id());
            $this->executeLedgerTransaction($accountId, $netAmount, 'deposit', "Kezelési költség: \${$fee}");

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Pénz kivétel (Bank -> Wallet)
     */
    public function withdraw(UserId $userId, int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidInputException("Csak pozitív összeget lehet kivenni.");
        }

        $this->db->beginTransaction();
        try {
            // [FIX] Deadlock védelem: Mindig a users táblát lockoljuk először a bank_accounts előtt!
            $this->db->executeQuery("SELECT id FROM users WHERE id = ? FOR UPDATE", [$userId->id()]);

            $accountId = $this->getAccountId($userId->id());
            
            // 1. Bank levonás & Log (Szabálypont 9.2: Központi Ledger hívás)
            $this->executeLedgerTransaction($accountId, -$amount, 'withdraw', "-");

            // 2. Wallet jóváírás
            $this->moneyService->addMoney($userId, $amount, 'bank_withdraw', "Banki kivét", 'bank', null);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Utalás számlaszámra
     * 
     * [2025-12-29 14:12:50] Optimalizálás: FOR UPDATE lock korábbi használata
     * A getAccount() helyett getAccountForUpdate() használata, amely már
     * tranzakció kezdetén FOR UPDATE lockot alkalmaz a race condition
     * jobb megelőzése érdekében.
     */
    public function transfer(UserId $senderId, int $targetAccountNumber, int $amount, string $note): void
    {
        if ($amount <= 0) {
            throw new InvalidInputException("Csak pozitív összeget lehet utalni.");
        }

        // [FIX] Cél számla létezésének ellenőrzése tranzakción kívül (olcsó check)
        $targetAccount = $this->findAccountByNumber($targetAccountNumber);
        if (!$targetAccount) {
            throw new InvalidInputException("A cél bankszámla nem létezik.");
        }

        $this->db->beginTransaction();
        try {
            // [FIX] Consistent lock ordering — mindig kisebb ID-t lock-oljuk először
            // Ez megakadályozza a deadlock-ot ha A→B és B→A egyszerre utal
            $sourceId = $this->getAccountId($senderId->id());
            $targetId = (int)$targetAccount['id'];

            if ($sourceId === $targetId) {
                throw new InvalidInputException("Magadnak nem utalhatsz.");
            }

            // Lock ordering: kisebb ID előbb
            $firstLockId = min($sourceId, $targetId);
            $secondLockId = max($sourceId, $targetId);
            
            $this->db->fetchOne("SELECT id FROM bank_accounts WHERE id = ? FOR UPDATE", [$firstLockId]);
            $this->db->fetchOne("SELECT id FROM bank_accounts WHERE id = ? FOR UPDATE", [$secondLockId]);

            // 1. Levonás tőlünk
            $this->executeLedgerTransaction($sourceId, -$amount, 'transfer_out', "Cél: $targetAccountNumber. $note", $targetId);

            // 2. Jóváírás neki
            $this->executeLedgerTransaction($targetId, $amount, 'transfer_in', "Feladó: " . $this->db->fetchOne("SELECT account_number FROM bank_accounts WHERE id = ?", [$sourceId]) . ". $note", $sourceId);

            // 3. Értesítés küldése a címzettnek
            $senderUsernameRaw = $this->db->fetchOne("SELECT username FROM users WHERE id = ?", [$senderId->id()]);
            $senderUsername = $senderUsernameRaw ?: 'Ismeretlen';
            $msg = "Utalása érkezett {$senderUsername} felhasználótól, utalt összeg: $" . number_format($amount, 0, ',', '.');
            if (!empty($note)) {
                $msg .= " (Megjegyzés: $note)";
            }
            
            $this->notificationService->send(
                (int)$targetAccount['user_id'],
                'bank_transfer',
                $msg,
                'bank',
                '/bank'
            );

            $this->db->commit();

            // [2026-02-28] FIX: AuditLogger — compliance 9.2
            $this->auditLogger->log(AuditLogger::TYPE_BANK_TRANSFER, $senderId->id(), [
                'amount'          => $amount,
                'target_account'  => $targetAccountNumber,
                'recipient_id'    => (int)$targetAccount['user_id'],
                'note'            => $note,
            ]);
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Tranzakció történet
     */
    public function getHistory(int $userId, int $limit = 50): array
    {
        $account = $this->getAccount($userId);
        if (!$account) {
            return [];
        }

        return $this->db->fetchAllAssociative(
            "SELECT id, account_id, type, amount, counterparty_account_id, description, created_at FROM bank_transactions WHERE account_id = ? ORDER BY created_at DESC LIMIT " . (int)$limit,
            [$account['id']]
        );
    }
    
    // Internal helpers
    private function getAccountId(int $userId): int
    {
        $id = $this->db->fetchOne("SELECT id FROM bank_accounts WHERE user_id = ?", [$userId]);
        if (!$id) {
            throw new GameException("Nincs bankszámlád.");
        }
        return (int)$id;
    }

    /**
     * Központi Ledger metódus a bankszámlákhoz (Szabálypont 9.2 compliance)
     * Kiszámolja a fedezetet, frissíti az egyenleget és azonnal naplózza a változást.
     */
    private function executeLedgerTransaction(int $accountId, int $amount, string $type, string $description, ?int $counterpartyId = null): void
    {
        // Pessimistic Lock
        $row = $this->db->fetchAssociative("SELECT balance FROM bank_accounts WHERE id = ? FOR UPDATE", [$accountId]);
        if ($row === false) {
            throw new GameException("A számla nem létezik.");
        }
        $currentBalance = (int) $row['balance'];
        
        $newBalance = $currentBalance + $amount;
        if ($newBalance < 0) {
            throw new GameException("Nincs elegendő fedezet.");
        }

        // Egyenleg frissítés
        $this->db->executeStatement("UPDATE bank_accounts SET balance = ? WHERE id = ?", [$newBalance, $accountId]);

        // Tranzakció azonnali naplózása
        $this->db->insert('bank_transactions', [
            'account_id' => $accountId,
            'type' => $type,
            'amount' => $amount,
            'counterparty_account_id' => $counterpartyId,
            'description' => substr($description, 0, 255),
            'created_at' => gmdate('Y-m-d H:i:s')
        ]);
    }
}
