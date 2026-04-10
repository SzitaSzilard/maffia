<?php
declare(strict_types=1);

namespace Netmafia\Modules\Bank\Actions;

use Netmafia\Modules\Bank\Domain\BankService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class BankHistoryAction
{
    private Twig $view;
    private BankService $bankService;

    public function __construct(Twig $view, BankService $bankService)
    {
        $this->view = $view;
        $this->bankService = $bankService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            // HTMX request esetén 401 vagy redirect
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // Kizárólag HTMX (ablakos) betöltés engedélyezése, böngészőből linkelés tiltása (HX-Guard)
        if (!$request->hasHeader('HX-Request')) {
            return $response->withHeader('Location', '/bank')->withStatus(302);
        }

        $history = $this->bankService->getHistory((int)$userId);

        return $this->view->render($response, 'bank/history.twig', [
            'history' => $history
        ]);
    }
}
