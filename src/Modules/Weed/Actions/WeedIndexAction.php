<?php
declare(strict_types=1);

namespace Netmafia\Modules\Weed\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Item\Domain\InventoryService;
use Netmafia\Modules\Weed\WeedConfig;
use Doctrine\DBAL\Connection;
use Netmafia\Infrastructure\SessionService;
use Slim\Views\Twig;

class WeedIndexAction
{
    private Twig $view;
    private Connection $db;
    private InventoryService $inventory;
    private SessionService $session;

    public function __construct(Twig $view, Connection $db, InventoryService $inventory, SessionService $session)
    {
        $this->view = $view;
        $this->db = $db;
        $this->inventory = $inventory;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId      = (int) $request->getAttribute('user_id');
        $user        = $request->getAttribute('user');
        $countryCode = $user['country_code'] ?? 'US';


        // Cooldown adatok
        $userData = $this->db->fetchAssociative(
            "SELECT last_weed_plant_at, last_weed_harvest_at FROM users WHERE id = ?",
            [$userId]
        );

        $plantCdRemaining = 0;
        if ($userData && !empty($userData['last_weed_plant_at'])) {
            $remaining        = strtotime($userData['last_weed_plant_at']) + (WeedConfig::PLANT_COOLDOWN_HOURS * 3600) - time();
            $plantCdRemaining = max(0, $remaining);
        }

        $harvestCdRemaining = 0;
        if ($userData && !empty($userData['last_weed_harvest_at'])) {
            $remaining          = strtotime($userData['last_weed_harvest_at']) + (WeedConfig::HARVEST_COOLDOWN_HOURS * 3600) - time();
            $harvestCdRemaining = max(0, $remaining);
        }

        // Statisztikák
        $totalSeeds      = $this->inventory->getItemQuantity($userId, WeedConfig::ITEM_WEED_SEED);
        $localPlantations  = (int) $this->db->fetchOne(
            "SELECT COALESCE(amount, 0) FROM user_weed_plantations WHERE user_id = ? AND country_code = ?",
            [$userId, $countryCode]
        );
        $globalPlantations = (int) $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) FROM user_weed_plantations WHERE user_id = ?",
            [$userId]
        );

        // Ország neve
        $countryName = $this->db->fetchOne(
            "SELECT name_hu FROM countries WHERE code = ?",
            [$countryCode]
        );
        $countryName = $countryName ?: $countryCode;

        $baseQuality = WeedConfig::getCountryBaseQuality($countryCode);

        // Rang ellenőrzése
        $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo((int)$user['xp']);
        $canPlant = $rankInfo['index'] >= 3;
        $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[3] ?? 'Gengszter';

        return $this->view->render($response, 'modules/weed/index.twig', [
            'user'               => $user,
            'is_ajax'            => $request->hasHeader('HX-Request'),
            'total_seeds'        => $totalSeeds,
            'local_plantations'  => $localPlantations,
            'global_plantations' => $globalPlantations,
            'base_quality'       => $baseQuality,
            'max_plants'         => WeedConfig::MAX_PLANTS_PER_COUNTRY,
            'plant_cd'           => $plantCdRemaining,
            'harvest_cd'         => $harvestCdRemaining,
            'country_name'       => $countryName,
            'can_plant'          => $canPlant,
            'required_rank_name' => $requiredRankName,
        ]);
    }
}
