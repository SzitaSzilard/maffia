<?php
declare(strict_types=1);

namespace Netmafia\Modules\Shop\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Shop\Domain\ShopService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Slim\Views\Twig;

class ShopViewAction
{
    private Twig $twig;
    private ShopService $shopService;
    private AuthService $authService;

    public function __construct(Twig $twig, ShopService $shopService, AuthService $authService)
    {
        $this->twig = $twig;
        $this->shopService = $shopService;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $sessionUserId = $request->getAttribute('user_id');
        $user = $this->authService->getAuthenticatedUser((int)$sessionUserId);
        
        $shopData = $this->shopService->getShopData();
        
        $viewData = [
            'user' => $user,
            'weapons' => $shopData['weapons'],
            'armors' => $shopData['armors'],
            'consumables' => $shopData['consumables'],
            'jets' => $shopData['jets'],
            'next_restock' => $shopData['next_restock'],
            'is_ajax' => $request->hasHeader('HX-Request')
        ];
        
        return $this->twig->render($response, 'game/shop/index.twig', $viewData);
    }
}
