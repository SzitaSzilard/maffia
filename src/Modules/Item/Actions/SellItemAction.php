<?php
declare(strict_types=1);

namespace Netmafia\Modules\Item\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Item\Domain\ItemService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

/**
 * SellItemAction - Tárgy eladása
 */
class SellItemAction
{
    private ItemService $itemService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;
    
    public function __construct(ItemService $itemService, SessionService $session)
    {
        $this->itemService = $itemService;
        $this->session = $session;
    }
    
    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        
        if (!$userId) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        
        $data = $request->getParsedBody();
        $userItemId = (int)($data['user_item_id'] ?? 0);
        $quantity = (int)($data['quantity'] ?? 1);
        
        if ($userItemId <= 0) {
            $this->session->flash('item_error', 'Érvénytelen tárgy!');
            return $response->withHeader('Location', '/otthon?tab=targyaid')->withStatus(302);
        }
        
        if ($quantity <= 0) {
            $this->session->flash('item_error', 'Érvénytelen mennyiség!');
            return $response->withHeader('Location', '/otthon?tab=targyaid')->withStatus(302);
        }
        
        try {
            $sellPrice = $this->itemService->sellItem(UserId::of($userId), $userItemId, $quantity);
            $this->session->flash('item_success', "Eladva! +$" . number_format($sellPrice, 0, ',', ','));
        } catch (\Throwable $e) {
            $this->session->flash('item_error', $e->getMessage());
        }
        
        return $response->withHeader('Location', '/otthon?tab=targyaid')->withStatus(302);
    }
}
