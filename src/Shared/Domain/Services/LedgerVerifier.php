<?php
declare(strict_types=1);

namespace Netmafia\Shared\Domain\Services;

use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Credits\Domain\CreditService;
use Netmafia\Modules\AmmoFactory\Domain\BulletService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Doctrine\DBAL\Connection;

/**
 * LedgerVerifier — Kereszt-currency integritás ellenőrző
 *
 * Egyetlen pontból ellenőrzi a pénz + kredit + töltény egyenlegek
 * konzisztenciáját a ledger (transaction napló) alapján.
 *
 * Használat:
 *   $result = $ledger->verifyUser(UserId::of(42));
 *   if (!$result['money']['valid']) { // fraud/bug! }
 *
 * Fraud érzékelési logika:
 *   Ha users.money != utolsó money_transactions.balance_after
 *    → Valaki (vagy valami) direkt SQL-lel módosította az egyenleget
 *    → Fraud alert kerül az audit_logs-ba
 */
class LedgerVerifier
{
    private MoneyService  $moneyService;
    private CreditService $creditService;
    private BulletService $bulletService;
    private Connection    $db;
    private AuditLogger   $auditLogger;

    public function __construct(
        MoneyService  $moneyService,
        CreditService $creditService,
        BulletService $bulletService,
        Connection    $db,
        AuditLogger   $auditLogger
    ) {
        $this->moneyService  = $moneyService;
        $this->creditService = $creditService;
        $this->bulletService = $bulletService;
        $this->db            = $db;
        $this->auditLogger   = $auditLogger;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  EGYEDI USER
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Teljes integritás ellenőrzés egy userhez (mind három currency)
     *
     * @return array{
     *   money:   array{valid: bool, expected: int, actual: int, difference: int},
     *   credits: array{valid: bool, expected: int|object, actual: int, difference: int},
     *   bullets: array{valid: bool, expected: int, actual: int, difference: int},
     *   all_valid: bool
     * }
     */
    public function verifyUser(UserId $userId): array
    {
        $money   = $this->moneyService->verifyUserIntegrity($userId);
        $credits = $this->creditService->verifyUserIntegrity($userId);
        $bullets = $this->bulletService->verifyUserIntegrity($userId);

        $allValid = $money['valid'] && $credits['valid'] && $bullets['valid'];

        if (!$allValid) {
            // Összefoglaló fraud alert az audit_logs-ba
            $this->auditLogger->log(AuditLogger::TYPE_SUSPICIOUS, $userId->id(), [
                'check'         => 'ledger_integrity',
                'money_valid'   => $money['valid'],
                'credits_valid' => $credits['valid'],
                'bullets_valid' => $bullets['valid'],
                'money_diff'    => $money['difference'],
                'credits_diff'  => $credits['difference'],
                'bullets_diff'  => $bullets['difference'],
            ]);
        }

        return [
            'money'     => $money,
            'credits'   => $credits,
            'bullets'   => $bullets,
            'all_valid' => $allValid,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  TRANSFER CHAIN ELLENŐRZÉS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Utalás utáni megmaradás törvény ellenőrzés
     *
     * Példa: A utal B-nek 2$
     *   A: balance_before=50, balance_after=48 → diff = -2
     *   B: balance_before=10, balance_after=12 → diff = +2
     *   Összeg: -2 + 2 = 0 → RENDBEN (megmaradás törvénye)
     *
     * Ha != 0 → Pénz a semmiből jött VAGY eltűnt → FRAUD
     *
     * @param string $currency  'money' | 'credits' | 'bullets'
     */
    public function verifyTransferChain(UserId $from, UserId $to, string $currency): bool
    {
        $table = match($currency) {
            'money'   => 'money_transactions',
            'credits' => 'credit_transactions',
            'bullets' => 'bullet_transactions',
            default => throw new \InvalidArgumentException("Unknown currency: $currency"),
        };

        // Utolsó tranzakció mindkét félnél
        $fromTx = $this->db->fetchAssociative(
            "SELECT amount, balance_before, balance_after FROM {$table}
             WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$from->id()]
        );

        $toTx = $this->db->fetchAssociative(
            "SELECT amount, balance_before, balance_after FROM {$table}
             WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$to->id()]
        );

        if (!$fromTx || !$toTx) {
            return true; // Nincs tranzakció, nem tudjuk ellenőrizni
        }

        // Megmaradás törvény: a leadott összeget pontosan a másik fél kapta
        $fromDiff = (int)$fromTx['balance_after'] - (int)$fromTx['balance_before'];
        $toDiff   = (int)$toTx['balance_after']   - (int)$toTx['balance_before'];

        $conserved = ($fromDiff + $toDiff === 0);

        if (!$conserved) {
            $this->auditLogger->log(AuditLogger::TYPE_SUSPICIOUS, $from->id(), [
                'check'      => 'transfer_chain_violation',
                'currency'   => $currency,
                'from_diff'  => $fromDiff,
                'to_diff'    => $toDiff,
                'net'        => $fromDiff + $toDiff,
                'to_user_id' => $to->id(),
            ]);
        }

        return $conserved;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  BATCH ELLENŐRZÉS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Batch ellenőrzés — összes user, mindhárom currency egyszerre
     *
     * @return array{
     *   checked: int,
     *   all_valid: int,
     *   violations: array,
     *   by_currency: array{money: array, credits: array, bullets: array}
     * }
     */
    public function verifyAll(): array
    {
        $moneyResult   = $this->moneyService->verifyAllUsersIntegrity();
        $creditResult  = $this->creditService->verifyAllUsersIntegrity();
        $bulletResult  = $this->bulletService->verifyAllUsersIntegrity();

        // Egyesített violations
        $allViolations = [];

        foreach ($moneyResult['violations'] as $v) {
            $v['currency'] = 'money';
            $allViolations[] = $v;
        }
        foreach ($creditResult['violations'] as $v) {
            $v['currency'] = 'credits';
            $allViolations[] = $v;
        }
        foreach ($bulletResult['violations'] as $v) {
            $v['currency'] = 'bullets';
            $allViolations[] = $v;
        }

        return [
            'checked'    => $moneyResult['checked'],
            'all_valid'  => $moneyResult['valid'] + $creditResult['valid'] + $bulletResult['valid'],
            'violations' => $allViolations,
            'by_currency' => [
                'money'   => $moneyResult,
                'credits' => $creditResult,
                'bullets' => $bulletResult,
            ],
        ];
    }
}
