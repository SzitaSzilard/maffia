<?php
declare(strict_types=1);

namespace Netmafia\Modules\Messages\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Messages\Domain\MessageService;
use Netmafia\Shared\Domain\RankCalculator;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * MessagesViewAction - Üzenetek megjelenítése (Bejövő/Kimenő/Küldés tab)
 */
class MessagesViewAction
{
    private Twig $view;
    private MessageService $messageService;
    private AuthService $authService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(Twig $view, MessageService $messageService, AuthService $authService, SessionService $session)
    {
        $this->view = $view;
        $this->messageService = $messageService;
        $this->authService = $authService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');

        $user = $this->authService->getAuthenticatedUser((int)$userId);

        // Tab meghatározása (alapértelmezett: inbox)
        $tab = $args['tab'] ?? 'inbox';
        $isAjax = $request->hasHeader('HX-Request');

        // [2026-02-28] FIX: SessionService használata $_SESSION helyett
        // Tömb adatokhoz get()+remove(), string adatokhoz getFlash()
        $errors = $this->session->get('message_errors');
        $this->session->remove('message_errors');
        $success = $this->session->getFlash('message_success');
        $formData = $this->session->get('message_form');
        $this->session->remove('message_form');

        $data = [
            'user' => $user,
            'is_ajax' => $isAjax,
            'active_tab' => $tab,
            'page_title' => 'Üzenetek',
            'message_errors' => $errors,
            'message_success' => $success,
            'message_form' => $formData,
        ];

        // Pagination setup
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        
        $totalItems = 0;
        $messages = [];

        // Tab alapján adatok betöltése
        switch ($tab) {
            case 'outbox':
                $this->messageService->cleanupOldMessages((int)$userId, 'outbox');
                $messages = $this->messageService->getMessages((int)$userId, 'outbox', $limit, $offset);
                $totalItems = $this->messageService->getMessageCount((int)$userId, 'outbox');
                $data['tab_title'] = 'Kimenő';
                break;
            case 'send':
                $data['tab_title'] = 'Küldés';
                // Címzett és tárgy előtöltése válaszhoz
                $data['recipient'] = $request->getQueryParams()['to'] ?? '';
                $data['prefill_subject'] = $request->getQueryParams()['subject'] ?? '';
                break;
            default: // inbox
                $this->messageService->cleanupOldMessages((int)$userId, 'inbox');
                $messages = $this->messageService->getMessages((int)$userId, 'inbox', $limit, $offset);
                $totalItems = $this->messageService->getMessageCount((int)$userId, 'inbox');
                $data['tab_title'] = 'Bejövő';
                
                $unreadCount = $this->messageService->getUnreadCount((int)$userId);
                $data['unread_count'] = $unreadCount;
                
                // Csak akkor hívjuk a markAllAsRead-et, ha tényleg van olvasatlan üzenet
                if ($unreadCount > 0) {
                    $this->messageService->markAllAsRead((int)$userId);
                }
                break;
        }

        $data['messages'] = $messages;
        $data['pagination'] = [
            'current_page' => $page,
            'total_pages' => ceil($totalItems / $limit),
            'total_items' => $totalItems,
            'limit' => $limit
        ];

        return $this->view->render($response, 'messages/layout.twig', $data);
    }
}
