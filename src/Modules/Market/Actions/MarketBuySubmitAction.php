<?php
declare(strict_types=1);

namespace Netmafia\Modules\Market\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Market\Domain\MarketService;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;
use Slim\Views\Twig;

class MarketBuySubmitAction
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

        $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)($user['xp'] ?? 0));
        if ($rankInfo['index'] < 2 && empty($user['is_admin'])) {
            $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[2] ?? 'Kezdő gengszter';
            return $this->errorResponse($response, "A piac használatához szükséges minimum rang: {$requiredRankName}");
        }

        $marketId = (int)($args['id'] ?? 0);
        
        $data = (array)$request->getParsedBody();
        $quantity = isset($data['quantity']) ? (int)$data['quantity'] : 1;

        if ($marketId <= 0 || $quantity <= 0) {
            return $this->errorResponse($response, 'Érvénytelen bemenet!');
        }

        try {
            $this->marketService->buyItem((int)$user['id'], $marketId, $quantity);

            $triggerData = [
                'notification' => [
                    'type' => 'success',
                    'message' => 'Sikeres vásárlás!'
                ],
                'reloadMarket' => true,
                'updateStats' => true
            ];
            
            $response->getBody()->write('');
            return $response->withHeader('HX-Trigger', json_encode($triggerData));
            
        } catch (GameException | InvalidInputException $e) {
            return $this->errorResponse($response, $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Market buy error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Váratlan hiba történt a vásárlás során.');
        }
    }

    private function errorResponse(Response $response, string $message): Response
    {
        $triggerData = [
            'notification' => [
                'type' => 'error',
                'message' => $message
            ]
        ];
        
        $response->getBody()->write('');
        return $response->withHeader('HX-Trigger', json_encode($triggerData));
    }
}
