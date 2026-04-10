<?php
declare(strict_types=1);

namespace Netmafia\Modules\Weed\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Weed\Domain\WeedService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Exceptions\GameException;

class WeedPlantAction
{
    private WeedService $weedService;
    private SessionService $session;

    public function __construct(WeedService $weedService, SessionService $session)
    {
        $this->weedService = $weedService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId      = UserId::of((int) $request->getAttribute('user_id'));
        $user        = $request->getAttribute('user');
        $countryCode = $user['country_code'] ?? 'US';

        $parsedBody = (array) $request->getParsedBody();
        $amount     = (int) ($parsedBody['amount'] ?? 0);

        try {
            $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)$user['xp']);
            if ($rankInfo['index'] < 3) {
                $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[3] ?? 'Gengszter';
                throw new GameException("A vadkender ültetés használatához szükséges minimum rang: {$requiredRankName}");
            }

            $this->weedService->plant($userId, $amount, $countryCode);
            $this->session->flash('success', "Sikeresen elültettél {$amount} db vadkendermagot! Jó növekedést! 🌿");
        } catch (\Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $response->withHeader('Location', '/vadkender')->withStatus(303);
    }
}
