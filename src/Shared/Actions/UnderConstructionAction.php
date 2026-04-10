<?php
declare(strict_types=1);

namespace Netmafia\Shared\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class UnderConstructionAction
{
    private Twig $view;
    private AuthService $authService;

    public function __construct(Twig $view, AuthService $authService)
    {
        $this->view = $view;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        // Module név az URL path-ből (pl. /banda -> "banda")
        $moduleName = ucfirst($args['module'] ?? 'Ismeretlen modul');
        
        // [2026-02-28] FIX: request attribute használata $_SESSION helyett
        $userId = $request->getAttribute('user_id');
        $user = $userId ? $this->authService->getAuthenticatedUser((int)$userId) : null;

        return $this->view->render($response, 'placeholder.twig', [
            'module_name' => $moduleName,
            'page_title' => $moduleName,
            'user' => $user
        ]);
    }
}
