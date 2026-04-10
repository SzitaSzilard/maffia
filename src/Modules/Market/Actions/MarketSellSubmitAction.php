<?php
declare(strict_types=1);

namespace Netmafia\Modules\Market\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Market\Domain\MarketService;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

class MarketSellSubmitAction
{
    private MarketService $marketService;

    public function __construct(MarketService $marketService)
    {
        $this->marketService = $marketService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user) {
            return $response->withStatus(401);
        }

        $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)($user['xp'] ?? 0));
        if ($rankInfo['index'] < 2 && empty($user['is_admin'])) {
            $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[2] ?? 'Kezdő gengszter';
            return $this->errorResponse($response, "A piac használatához szükséges minimum rang: {$requiredRankName}");
        }

        if (!$this->marketService->canUserSell($user)) {
            return $this->errorResponse($response, 'Nincs megfelelő rangod az eladáshoz!');
        }

        $data = $request->getParsedBody();
        $category = trim((string)($data['category'] ?? ''));
        
        // Whitelist validáció (§10.1)
        if (!array_key_exists($category, \Netmafia\Modules\Market\MarketConfig::CATEGORIES)) {
            return $this->errorResponse($response, 'Érvénytelen kategória!');
        }

        $itemId = isset($data['item_id']) && $data['item_id'] !== '' ? (int)$data['item_id'] : null;
        $quantity = (int)($data['quantity'] ?? 1);
        $price = (int)($data['price'] ?? 0);
        $currency = trim((string)($data['currency'] ?? 'money'));

        try {
            // Először ellenőrizzük, hogy van-e ennyi neki
            // (A dupla zárolás/tranzakció a Service-ben történik)
            $this->marketService->verifyItemFromDb((int)$user['id'], $category, $itemId, $quantity);
            
            // Ha rendben van, betesszük a piacra
            $this->marketService->listItemOnMarket((int)$user['id'], $category, $itemId, $quantity, $price, $currency);

            $triggerData = [
                'notification' => [
                    'type' => 'success',
                    'message' => 'Sikeresen meghirdetted a Piacon!'
                ],
                'updateStats' => true,
                'clearSellForm' => true
            ];

            $response->getBody()->write(
                '<div class="alert-success">Sikeresen meghirdetted a Piacon!</div>'
            );
            
            return $response->withHeader('HX-Trigger', json_encode($triggerData));

        } catch (GameException | InvalidInputException $e) {
            return $this->errorResponse($response, $e->getMessage());
        } catch (\Throwable $e) {
            error_log("MARKET ERROR: " . $e->getMessage());
            return $this->errorResponse($response, 'Váratlan hiba történt a piacra helyezéskor.');
        }
    }

    private function errorResponse(Response $response, string $message): Response
    {
        $response->getBody()->write('<div class="alert-error">' . htmlspecialchars($message) . '</div>');
        return $response;
    }
}
