<?php
declare(strict_types=1);

namespace Netmafia\Modules\Market\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Market\Domain\MarketService;
use Netmafia\Modules\Market\MarketConfig;

class MarketListCategoryAction
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
        
        if (!array_key_exists($category, MarketConfig::CATEGORIES)) {
            $response->getBody()->write('<div class="alert-error">Érvénytelen kategória.</div>');
            return $response;
        }

        $listings = $this->marketService->getMarketGroupedListings($category);
        $categoryName = MarketConfig::CATEGORIES[$category];

        return $this->view->render($response, 'market/partials/listings_table.twig', [
            'category' => $category,
            'categoryName' => mb_strtolower($categoryName),
            'listings' => $listings
        ]);
    }
}
