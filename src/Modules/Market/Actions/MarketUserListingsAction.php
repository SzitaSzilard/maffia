<?php
declare(strict_types=1);

namespace Netmafia\Modules\Market\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Market\Domain\MarketService;
use Netmafia\Modules\Market\MarketConfig;

class MarketUserListingsAction
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

        $userId = (int)$user['id'];

        $activeListings = $this->marketService->getUserActiveListings($userId);
        $recentSales = $this->marketService->getUserRecentSales($userId, 30);
        $recentPurchases = $this->marketService->getUserRecentPurchases($userId, 30);

        return $this->view->render($response, 'market/partials/user_listings.twig', [
            'activeListings' => $activeListings,
            'recentSales' => $recentSales,
            'recentPurchases' => $recentPurchases,
            'categories' => MarketConfig::CATEGORIES
        ]);
    }
}
