<?php
declare(strict_types=1);

namespace Netmafia\Modules\Notifications\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Notifications\Domain\NotificationService;
use Netmafia\Shared\Domain\RankCalculator;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * NotificationsViewAction - Értesítések megjelenítése
 */
class NotificationsViewAction
{
    private Twig $view;
    private NotificationService $notificationService;
    private AuthService $authService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(Twig $view, NotificationService $notificationService, AuthService $authService, SessionService $session)
    {
        $this->view = $view;
        $this->notificationService = $notificationService;
        $this->authService = $authService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $user = $this->authService->getAuthenticatedUser((int)$userId);

        $isAjax = $request->hasHeader('HX-Request');

        // Régi értesítések törlése
        $this->notificationService->cleanupOld((int)$userId);

        // Értesítések lekérdezése
        $notifications = $this->notificationService->getAll((int)$userId);
        $unreadCount = $this->notificationService->getUnreadCount((int)$userId);

        // Összes olvasottnak jelölése
        $this->notificationService->markAllAsRead((int)$userId);

        $data = [
            'user' => $user,
            'is_ajax' => $isAjax,
            'page_title' => 'Értesítések',
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ];

        return $this->view->render($response, 'notifications/layout.twig', $data);
    }
}
