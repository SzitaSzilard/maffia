<?php
declare(strict_types=1);

namespace Netmafia\Modules\Market\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Market\Domain\MarketService;
use Netmafia\Modules\Market\MarketConfig;

class MarketSellFormAction
{
    private Twig $view;
    private MarketService $marketService;

    public function __construct(Twig $view, MarketService $marketService)
    {
        $this->view = $view;
        $this->marketService = $marketService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $response->withStatus(401);
        }

        // Rang ellenőrzése ("Gengszter") vagy admin
        $canSell = $this->marketService->canUserSell($user);

        return $this->view->render($response, 'market/partials/sell_form.twig', [
            'canSell' => $canSell,
            'categories' => MarketConfig::CATEGORIES
        ]);
    }
}
