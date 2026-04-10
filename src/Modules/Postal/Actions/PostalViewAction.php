<?php
declare(strict_types=1);

namespace Netmafia\Modules\Postal\Actions;

use Netmafia\Modules\Postal\PostalConfig;
use Netmafia\Modules\Postal\Domain\PostalService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;


class PostalViewAction
{
    private PostalService $postalService;
    private SessionService $session;
    private Twig $view;

    public function __construct(PostalService $postalService, SessionService $session, Twig $view)
    {
        $this->postalService = $postalService;
        $this->session = $session;
        $this->view = $view;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = (int)$user['id'];
        
        // rank_name már benne van a user-ben az AuthMiddleware-ből
        
        $isAjax = $request->hasHeader('HX-Request');

        // Automatikus kézbesítés: lejárt csomagok átadása
        $justDelivered = $this->postalService->deliverPendingPackages($userId);

        // Még várakozó csomagok (nem járt le)
        $pendingPackages = $this->postalService->getPendingPackages($userId);

        // Session kosár
        $cart = $this->session->get('postal_cart', []);
        $shippingCost = $this->postalService->calculateShippingCost($cart);

        // [Uj] Feladott csomagok lekérdezése
        $sentPackages = $this->postalService->getSentPackages($userId);

        // Rang ellenőrző logika
        $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)($user['xp'] ?? 0));
        $canAccess = $rankInfo['index'] >= 3;
        $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[3] ?? 'Gengszter';

        $response = $this->view->render($response, 'postal/index.twig', [
            'user' => $user,
            'is_ajax' => $isAjax,
            'page_title' => 'Posta',
            'active_categories' => PostalConfig::ACTIVE_CATEGORIES,
            'inactive_categories' => PostalConfig::INACTIVE_CATEGORIES,
            'cart' => $cart,
            'shipping_cost' => $shippingCost,
            'max_items' => PostalConfig::MAX_ITEMS_PER_PACKAGE,
            'pending_packages' => $pendingPackages,
            'sent_packages' => $sentPackages,
            'just_delivered' => $justDelivered,
            'can_access' => $canAccess,
            'required_rank_name' => $requiredRankName,
        ]);

        if ($isAjax) {
            $existingTrigger = $response->getHeaderLine('HX-Trigger');
            $triggers = $existingTrigger ? json_decode($existingTrigger, true) : [];
            $triggers['updatePostalBadge'] = count($pendingPackages);
            $response = $response->withHeader('HX-Trigger', json_encode($triggers));
        }

        return $response;
    }
}
