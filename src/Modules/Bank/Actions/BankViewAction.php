<?php
declare(strict_types=1);

namespace Netmafia\Modules\Bank\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Bank\Domain\BankService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class BankViewAction
{
    private Twig $view;
    private AuthService $authService;
    private BankService $bankService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(Twig $view, AuthService $authService, BankService $bankService, SessionService $session)
    {
        $this->view = $view;
        $this->authService = $authService;
        $this->bankService = $bankService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $user = $this->authService->getAuthenticatedUser((int)$userId);
        $account = $this->bankService->getAccount((int)$userId);
        
        return $this->view->render($response, 'bank/index.twig', [
            'user' => $user,
            'account' => $account,
            'page_title' => 'Bank',
            'is_ajax' => $request->hasHeader('HX-Request')
        ]);
    }
}
