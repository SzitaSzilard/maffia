<?php
declare(strict_types=1);

namespace Netmafia\Modules\Kocsma\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Infrastructure\AuditLogger;

class KocsmaService
{
    private Connection $db;
    // [2026-02-28] FIX: AuditLogger injektálva — compliance 9.2
    private AuditLogger $auditLogger;

    public function __construct(Connection $db, AuditLogger $auditLogger)
    {
        $this->db = $db;
        $this->auditLogger = $auditLogger;
    }

    /**
     * [2026-02-15] FIX: SQL string concat javítás – bár (int) cast-olva volt,
     * a paraméteres binding konzisztensebb és biztonságosabb.
     */
    public function getRecentMessages(int $limit = \Netmafia\Modules\Kocsma\KocsmaConfig::RECENT_MESSAGES_LIMIT): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT m.id, m.user_id, m.username, m.message, m.created_at, u.is_admin FROM kocsma_messages m LEFT JOIN users u ON m.user_id = u.id ORDER BY m.created_at DESC LIMIT ?",
            [$limit],
            [\Doctrine\DBAL\ParameterType::INTEGER]
        );
    }

    /**
     * [2026-02-15] FIX: Input validáció hozzáadva – korábban nem volt ellenőrzés
     * az üzenet hosszára, üres stringre és XSS-re.
     */
    public function postMessage(int $userId, string $username, string $message): void
    {
        // [2026-02-15] FIX: Input validáció
        $message = trim($message);
        
        if (empty($message)) {
            throw new InvalidInputException('Az üzenet nem lehet üres!');
        }
        
        if (mb_strlen($message) > \Netmafia\Modules\Kocsma\KocsmaConfig::MAX_MESSAGE_LENGTH) {
            throw new InvalidInputException(
                sprintf('Az üzenet maximum %d karakter lehet!', \Netmafia\Modules\Kocsma\KocsmaConfig::MAX_MESSAGE_LENGTH)
            );
        }
        
        // [FIX] htmlspecialchars eltávolítva — Twig auto-escape ({{ }}) már véd XSS ellen
        // Dupla kódolás problémát okozott (&amp; jelent meg & helyett)
        
        $this->db->insert('kocsma_messages', [
            'user_id' => $userId,
            'username' => $username,
            'message' => $message,
            'created_at' => gmdate('Y-m-d H:i:s')
        ]);

        // [2026-02-28] FIX: AuditLogger hívás — compliance 9.2
        $this->auditLogger->log(AuditLogger::TYPE_KOCSMA_MESSAGE, $userId, [
            'username' => $username,
            'message_length' => mb_strlen($message),
        ]);
        
        // TODO: Emit Event for WebSocket
    }
}
