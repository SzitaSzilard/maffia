<?php
declare(strict_types=1);

namespace Netmafia\Modules\Combat\Actions;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Netmafia\Modules\Combat\Domain\CombatService;

class CombatAttackAction
{
    private CombatService $service;

    public function __construct(CombatService $service)
    {
        $this->service = $service;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $request->getAttribute('user_id');
        $attackerId = (int)$userId;
        $defenderId = (int)$args['id'];
        
        $data = $request->getParsedBody();
        $ammo = (int)($data['ammo'] ?? 0);
        $user = $request->getAttribute('user');
        
        $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)($user['xp'] ?? 0));
        if ($rankInfo['index'] < 3 && empty($user['is_admin'])) {
            $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[3] ?? 'Gengszter';
            $html = "
            <div class='alert-error'>
                <strong>HIBA!</strong><br>
                A küzdelmek használatához szükséges minimum rang: {$requiredRankName}
            </div>";
            $response->getBody()->write($html);
            return $response;
        }

        try {
            $result = $this->service->executeFight($attackerId, $defenderId, $ammo);
            
            // Return validation/alert partial
            // Green box for win, Red for loss
            // We can return disjoint HTML to update header? Or just replace the target list container header?
            // Simplest: Return a div that is inserted into a #combat-result container
            
            $isWin = ($result['winner_id'] === $attackerId);
            $colorClass = $isWin ? 'alert-success' : 'alert-error';
            $title = $isWin ? 'GYŐZELEM!' : 'VERESÉG!';
            
            $html = "
            <div class='{$colorClass}'>
                <strong>{$title}</strong><br>
                " . ($isWin ? "Sikerült legyőznöd ellenfeled!" : "Sajnos alulmaradtál a küzdelemben.") . "<br>
                Okozott sebzés: <strong>{$result['damage_dealt']}</strong><br>
                " . ($result['money_stolen'] > 0 ? "Elvett pénz: <strong>$" . number_format($result['money_stolen']) . "</strong>" : "") . "
                " . (!empty($result['message']) ? "<br><em>{$result['message']}</em>" : "") . "
            </div>";
            
            $response->getBody()->write($html);
            // [2026-02-28] FIX: Cache Invalidation (Rule 7.2) - Trigger sidebar stat update
            return $response->withHeader('HX-Trigger', 'update-stats');

        } catch (\Throwable $e) {
            // Error alert
            $html = "
            <div class='alert-error'>
                <strong>HIBA!</strong><br>
                {$e->getMessage()}
            </div>";
            
            $response->getBody()->write($html);
            return $response;
        }
    }
}
