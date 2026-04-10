<?php
declare(strict_types=1);

namespace Netmafia\Modules\Shop\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Shop\Domain\ShopService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class ShopBuyAction
{
    private ShopService $shopService;

    public function __construct(ShopService $shopService)
    {
        $this->shopService = $shopService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody() ?? [];
        
        $itemId = (int)($data['item_id'] ?? 0);
        $quantity = (int)($data['quantity'] ?? 1);
        
        try {
            $this->shopService->buyItem(UserId::of($user['id']), $itemId, $quantity);
            
            // Return HTMX response with success message and trigger full page reload to update stats and shop list
            $html = '<div class="alert-success">Sikeres vásárlás!</div>';
            $response->getBody()->write($html);
            return $response
                ->withHeader('HX-Trigger-After-Swap', 'reloadShop')
                ->withHeader('HX-Trigger', json_encode(['updateStats' => true]));
                
        } catch (\Throwable $e) {
            $html = '<div class="alert-error">' . htmlspecialchars($e->getMessage()) . '</div>';
            $response->getBody()->write($html);
            return $response;
        }
    }
}
