<?php
declare(strict_types=1);

namespace Netmafia\Modules\Item\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Item\Domain\ItemService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

/**
 * UnequipItemAction - Fegyver/védelem levétele
 */
class UnequipItemAction
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
        
        if ($userItemId <= 0) {
            $this->session->flash('item_error', 'Érvénytelen tárgy!');
            return $response->withHeader('Location', '/otthon?tab=targyaid')->withStatus(302);
        }
        
        try {
            $this->itemService->unequipItem(UserId::of($userId), $userItemId);
            $this->session->flash('item_success', 'Tárgy levéve!');
        } catch (\Throwable $e) {
            $this->session->flash('item_error', $e->getMessage());
        }
        
        return $response->withHeader('Location', '/otthon?tab=targyaid')->withStatus(302);
    }
}
