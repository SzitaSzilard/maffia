<?php
declare(strict_types=1);

namespace Netmafia\Modules\Market\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Market\Domain\MarketService;
use Netmafia\Modules\Market\MarketConfig;


class MarketIndexAction
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
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // Rang ellenőrzése ("Kezdő gengszter" = 2) a teljes piac eléréséhez
        $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)($user['xp'] ?? 0));
        $canAccessMarket = ($rankInfo['index'] >= 2) || !empty($user['is_admin']);
        $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[2] ?? 'Kezdő gengszter';

        $canSell = $this->marketService->canUserSell($user);
        
        // rank_name már benne van a user-ben az AuthMiddleware-ből

        // Alapértelmezett kategória betöltése (fegyver) vagy URL query paraméter alapján
        $queryParams = $request->getQueryParams();
        $catParam = $queryParams['cat'] ?? 'weapon';
        $modeParam = $queryParams['mode'] ?? 'lista';

        if (!array_key_exists($catParam, MarketConfig::CATEGORIES)) {
            $catParam = 'weapon';
        }

        $defaultListings = $this->marketService->getMarketGroupedListings($catParam);
        $defaultCategoryName = MarketConfig::CATEGORIES[$catParam];

        // Adatok előkészítése a különböző fül-típusokhoz (F5 frissítés esetén)
        $tabData = [];
        if ($modeParam === 'eladasaid') {
            $userId = (int)$user['id'];
            $tabData = [
                'activeListings' => $this->marketService->getUserActiveListings($userId),
                'recentSales' => $this->marketService->getUserRecentSales($userId, 30),
                'recentPurchases' => $this->marketService->getUserRecentPurchases($userId, 30),
                'categories' => MarketConfig::CATEGORIES
            ];
        }

        return $this->view->render($response, 'market/index.twig', [
            'user' => $user,
            'is_ajax' => $request->hasHeader('HX-Request'),
            'can_access_market' => $canAccessMarket,
            'required_rank_name' => $requiredRankName,
            'canSell' => $canSell,
            'categories' => MarketConfig::CATEGORIES,
            'pageTitle' => 'Piac',
            'currentMode' => $modeParam, // 'lista', 'uj-aru', 'eladasaid'
            'currentCat' => $catParam,
            'defaultListings' => $defaultListings,
            'defaultCategoryName' => mb_strtolower($defaultCategoryName),
            'tabData' => $tabData
        ]);
    }
}
