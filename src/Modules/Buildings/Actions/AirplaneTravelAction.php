<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Buildings\TravelConfig;
use Netmafia\Modules\Buildings\Domain\BuildingService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\Item\Domain\BuffService;

class AirplaneTravelAction
{
    private BuildingService $buildingService;
    private MoneyService $moneyService;
    private Connection $db;

    private BuffService $buffService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(
        BuildingService $buildingService,
        MoneyService $moneyService,
        Connection $db,
        BuffService $buffService,
        SessionService $session
    ) {
        $this->buildingService = $buildingService;
        $this->moneyService = $moneyService;
        $this->db = $db;
        $this->buffService = $buffService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $data = $request->getParsedBody();
        $targetCountry = $data['target_country'] ?? null;

        if (!$targetCountry || !isset(TravelConfig::AIRPLANE_PRICES[$targetCountry])) {
            $this->session->flash('highway_error', 'Érvénytelen célország!');
            return $response->withHeader('Location', '/epulet/autopalya?tab=airplane')->withStatus(302);
        }

        $this->db->beginTransaction();
        try {
            // 1. Lock and fetch User
            $user = $this->db->fetchAssociative("SELECT id, username, country_code, money, last_airplane_travel_time FROM users WHERE id = ? FOR UPDATE", [$userId]);
            if (!$user) {
                throw new GameException("Felhasználó nem található.");
            }

            if ($user['country_code'] === $targetCountry) {
                throw new GameException("Már ebben az országban vagy!");
            }

            // --- MAGÁNREPÜLŐ ELLENŐRZÉS (Private Jets) ---
            // A leggyorsabb (legkisebb defense értékű) jet-et keressük a játékosnál
            $bestJet = $this->db->fetchAssociative(
                "SELECT i.defense as travel_time 
                 FROM user_items ui
                 JOIN items i ON i.id = ui.item_id
                 WHERE ui.user_id = ? AND i.type = 'jet' AND ui.quantity > 0
                 ORDER BY i.defense ASC LIMIT 1",
                [$userId]
            );

            $hasJet = $bestJet !== false;
            $baseCooldownMinutes = $hasJet ? (int)$bestJet['travel_time'] : TravelConfig::AIRPLANE_COOLDOWN_MINUTES;
            $ticketCost = $hasJet ? 0 : TravelConfig::AIRPLANE_PRICES[$targetCountry];

            // Calculate Effective Buffs
            $reductionParams = $this->buffService->getActiveBonus((int)$userId, 'cooldown_reduction', 'travel');
            $effectiveMinutes = \Netmafia\Modules\Buildings\Domain\TravelCalculator::calculateEffectiveCooldown((float)$reductionParams, $baseCooldownMinutes);

            // 2. Checking Cooldown (Specific to Airplanes)
            $lastTravelStr = $user['last_airplane_travel_time'] ?? null;
            if ($lastTravelStr) {
                $lastTravelTimestamp = strtotime($lastTravelStr);
                $secondsPassed = time() - $lastTravelTimestamp;
                $cooldownSeconds = $effectiveMinutes * 60;
                
                if ($secondsPassed < $cooldownSeconds) {
                    $remMinutes = ceil(($cooldownSeconds - $secondsPassed) / 60);
                    throw new GameException("Még várnod kell {$remMinutes} percet az utazásig!");
                }
            }

            // 3. Economy (Check and Spend money)
            if ($ticketCost > 0) {
                // This will throw InsufficientBalanceException if not enough money
                $this->moneyService->spendMoney(
                    UserId::of((int)$userId),
                    $ticketCost,
                    'spend',
                    "Repülőjegy ({$targetCountry})",
                    'building_usage',
                    null
                );
            }

            // 4. Revenue Share for Airport Owner
            $currentCountry = $user['country_code'];
            // Since we don't have an "airport" type formally defined yet in usage, we use "airport"
            $airport = $this->buildingService->getByCountryAndType($currentCountry, 'airport');
            
            if ($airport && $airport['owner_id']) {
                // Use the standardized processOwnerRevenue from BuildingService
                $this->buildingService->processOwnerRevenue(
                    (int)$airport['id'],
                    $ticketCost,
                    "Repülőjegy eladás ({$user['username']})"
                );
                
                // Update total revenue and uses for the building stats
                $this->db->executeStatement(
                    "UPDATE buildings SET total_uses = total_uses + 1, total_revenue = total_revenue + ? WHERE id = ?",
                    [$ticketCost, $airport['id']]
                );
            }

            // 5. Update Location and Cooldown
            $targetTs = time() + (int)($effectiveMinutes * 60);
            $dateStr = gmdate('Y-m-d H:i:s', $targetTs);
            
            try {
                $this->db->executeStatement("SET @audit_source = ?", ['AirplaneTravelAction::invoke']);
                $this->db->executeStatement(
                    "UPDATE users SET country_code = ?, last_airplane_travel_time = UTC_TIMESTAMP(), airplane_cooldown_until = ? WHERE id = ?",
                    [$targetCountry, $dateStr, $userId]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $this->db->commit();
            
            $this->session->flash('highway_success', 'Sikeresen elutaztál repülővel ' . $targetCountry . ' országba!');
            return $response->withHeader('Location', '/epulet/autopalya')->withStatus(302);

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->session->flash('highway_error', $e->getMessage());
            return $response->withHeader('Location', '/epulet/autopalya?tab=airplane')->withStatus(302);
        }
    }
}
