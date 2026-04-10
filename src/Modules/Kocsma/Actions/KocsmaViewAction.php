<?php
declare(strict_types=1);

namespace Netmafia\Modules\Kocsma\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Kocsma\Domain\KocsmaService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class KocsmaViewAction
{
    private KocsmaService $service;
    private Twig $view;
    private AuthService $authService;

    public function __construct(KocsmaService $service, Twig $view, AuthService $authService)
    {
        $this->service = $service;
        $this->view = $view;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $messages = $this->service->getRecentMessages();
        
        $userId = $request->getAttribute('user_id');
        $user = $userId ? $this->authService->getAuthenticatedUser((int)$userId) : null;
        
        return $this->view->render($response, 'kocsma/index.twig', [
            'messages' => $messages,
            'is_ajax' => $request->hasHeader('HX-Request'),
            'page_title' => 'Kocsma',
            'user' => $user
        ]);
    }
}
