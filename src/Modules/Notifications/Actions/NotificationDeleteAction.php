<?php
declare(strict_types=1);

namespace Netmafia\Modules\Notifications\Actions;

use Netmafia\Modules\Notifications\Domain\NotificationService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * NotificationDeleteAction - Kijelölt értesítések törlése
 */
class NotificationDeleteAction
{
    private NotificationService $notificationService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(NotificationService $notificationService, SessionService $session)
    {
        $this->notificationService = $notificationService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $data = $request->getParsedBody();
        $notificationIds = $data['notification_ids'] ?? [];

        // Konvertálás int tömbbé
        $notificationIds = array_map('intval', array_filter((array)$notificationIds));

        if (!empty($notificationIds)) {
            $deleted = $this->notificationService->delete((int)$userId, $notificationIds);
            // [2026-02-28] FIX: SessionService flash() használata $_SESSION helyett
            $this->session->flash('notification_success', $deleted . ' értesítés törölve!');
        }

        return $response
            ->withHeader('Location', '/ertesitesek')
            ->withHeader('HX-Redirect', '/ertesitesek')
            ->withStatus(302);
    }
}
