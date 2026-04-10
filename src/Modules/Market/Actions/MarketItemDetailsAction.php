<?php
declare(strict_types=1);

namespace Netmafia\Modules\Market\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Market\Domain\MarketService;
use Netmafia\Modules\Market\MarketConfig;

class MarketItemDetailsAction
{
    private Twig $view;
    private MarketService $marketService;

    public function __construct(Twig $view, MarketService $marketService)
    {
        $this->view = $view;
        $this->marketService = $marketService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $response->withStatus(401);
        }

        $category = $args['category'] ?? '';
        $itemId = $args['id'] ?? '';
        
        if (!array_key_exists($category, MarketConfig::CATEGORIES)) {
            $response->getBody()->write('<tr><td colspan="10" class="text-danger p-2">Érvénytelen kategória.</td></tr>');
            return $response;
        }

        $sellers = $this->marketService->getMarketSellers($category, $itemId);

        return $this->view->render($response, 'market/partials/item_sellers.twig', [
            'sellers' => $sellers,
            'category' => $category,
            'itemId' => $itemId
        ]);
    }
}
