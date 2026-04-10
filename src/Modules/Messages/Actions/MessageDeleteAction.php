<?php
declare(strict_types=1);

namespace Netmafia\Modules\Messages\Actions;

use Netmafia\Modules\Messages\Domain\MessageService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * MessageDeleteAction - Kijelölt üzenetek törlése
 */
class MessageDeleteAction
{
    private MessageService $messageService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(MessageService $messageService, SessionService $session)
    {
        $this->messageService = $messageService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $data = $request->getParsedBody();
        $messageIds = $data['message_ids'] ?? [];
        $type = $data['type'] ?? 'inbox';
        
        // Type whitelist validáció
        if (!in_array($type, ['inbox', 'outbox'], true)) {
            $type = 'inbox';
        }

        // Konvertálás int tömbbé
        $messageIds = array_map('intval', array_filter((array)$messageIds));

        if (!empty($messageIds)) {
            $deleted = $this->messageService->deleteMessages((int)$userId, $messageIds, $type);
            // [2026-02-28] FIX: SessionService flash() használata $_SESSION helyett
            $this->session->flash('message_success', $deleted . ' üzenet törölve!');
        }

        $redirectUrl = '/uzenetek/' . $type;
        
        return $response
            ->withHeader('Location', $redirectUrl)
            ->withHeader('HX-Redirect', $redirectUrl)
            ->withStatus(302);
    }
}
