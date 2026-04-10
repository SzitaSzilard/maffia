<?php
declare(strict_types=1);

namespace Netmafia\Modules\Market\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Market\Domain\MarketService;
use Netmafia\Shared\Exceptions\GameException;

class MarketRevokeListingAction
{
    private MarketService $marketService;

    public function __construct(MarketService $marketService)
    {
        $this->marketService = $marketService;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $response->withStatus(401);
        }

        $marketId = (int)($args['id'] ?? 0);

        try {
            $this->marketService->revokeListing((int)$user['id'], $marketId);
            
            $triggerData = [
                'notification' => [
                    'type' => 'success',
                    'message' => 'A tárgyat sikeresen visszavontad a piacról!'
                ],
                'refreshUserListings' => true,
                'updateStats' => true
            ];
            
            $response->getBody()->write('');
            return $response->withHeader('HX-Trigger', json_encode($triggerData));

        } catch (GameException $e) {
            $triggerData = ['notification' => ['type' => 'error', 'message' => $e->getMessage()]];
            return $response->withHeader('HX-Trigger', json_encode($triggerData))->withStatus(200);
        } catch (\Throwable $e) {
            $triggerData = ['notification' => ['type' => 'error', 'message' => 'Váratlan hiba történt a visszavonás közben.']];
            return $response->withHeader('HX-Trigger', json_encode($triggerData))->withStatus(200);
        }
    }
}
