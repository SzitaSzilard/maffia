<?php
declare(strict_types=1);

namespace Netmafia\Modules\Weed\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Weed\Domain\WeedService;
use Netmafia\Modules\Weed\WeedConfig;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class WeedHarvestAction
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

        try {
            $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)$user['xp']);
            if ($rankInfo['index'] < 3) {
                $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[3] ?? 'Gengszter';
                throw new \Netmafia\Shared\Exceptions\GameException("A vadkender betakarítás használatához szükséges minimum rang: {$requiredRankName}");
            }

            $loot = $this->weedService->harvest($userId, $countryCode);

            $parts = [];

            if ($loot['seeds'] > 0) {
                $parts[] = "{$loot['seeds']} db mag";
            }

            foreach ($loot['items'] as $itemId => $qty) {
                $name    = WeedConfig::getItemName($itemId);
                $parts[] = "{$qty} db {$name}";
            }

            $planted = $loot['plant_count'];
            $listStr = implode(' | ', $parts);

            $this->session->flash(
                'success',
                "Betakarítottad {$planted} ültetvényedet! Zsákmány: {$listStr}."
            );
        } catch (\Throwable $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $response->withHeader('Location', '/vadkender')->withStatus(303);
    }
}
