<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Buildings\TravelConfig;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HighwayStickerAction
{
    private Connection $db;
    private MoneyService $moneyService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(
        Connection $db,
        MoneyService $moneyService,
        SessionService $session
    ) {
        $this->db = $db;
        $this->moneyService = $moneyService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $data = $request->getParsedBody();
        $level = (int)($data['sticker_level'] ?? 0);
        $duration = $data['sticker_duration'] ?? '';

        // Basic Validation
        if (!isset(TravelConfig::STICKER_PRICES[$level][$duration])) {
            $this->session->flash('highway_error', 'Érvénytelen matrica típus vagy időtartam!');
            return $response->withHeader('Location', '/epulet/autopalya')->withStatus(302);
        }

        $basePrice = TravelConfig::STICKER_PRICES[$level][$duration];
        
        $this->db->beginTransaction();
        try {
            // 1. Fetch User (Lock for Money)
            $user = $this->db->fetchAssociative("SELECT id, username, country_code, money, highway_sticker_level, highway_sticker_expiry FROM users WHERE id = ? FOR UPDATE", [$userId]);
            
            // 2. Fetch Owner of Highway in Current Country
            // We need to know who owns the building in the user's CURRENT location
            $userCountry = $user['country_code'];
            
            $building = $this->db->fetchAssociative(
                "SELECT id, owner_id, usage_price, country_code FROM buildings WHERE type = 'highway' AND country_code = ?",
                [$userCountry]
            );

            // Calculate dynamic price based on owner's setting
            $multiplier = 1.0;
            if ($building && isset($building['usage_price']) && $building['usage_price'] > 0) {
                $multiplier = $building['usage_price'] / 1000;
            }
            $price = (int)($basePrice * $multiplier);

            if ($user['money'] < $price) {
                throw new GameException("Nincs elég pénzed a matrica megvásárlásához! (Ár: {$price})");
            }

            // 3. Process Payment & Revenue Share
            $ownerShare = 0;
            if ($building && $building['owner_id']) {
                $ownerShare = (int)($price * (TravelConfig::OWNER_REVENUE_PERCENT / 100));
                
                // Credit Owner
                $this->moneyService->addMoney(
                    UserId::of((int)$building['owner_id']),
                    $ownerShare, 
                    'building_income',
                    "Autópálya matrica bevétel ({$user['username']}, {$userCountry})"
                );
            }

            // Deduct from Buyer
            $this->moneyService->spendMoney(
                UserId::of((int)$userId), 
                $price, 
                'purchase',
                "Autópálya matrica vétel"
            );

            // 4. Update User Sticker Status
            // Logic: Overwrite existing.
            // Expiry: NOW + duration
            $expiryDate = new \DateTime();
            if ($duration === TravelConfig::DURATION_WEEK) {
                $expiryDate->modify('+7 days');
            } elseif ($duration === TravelConfig::DURATION_MONTH) {
                $expiryDate->modify('+30 days');
            }

            try {
                $this->db->executeStatement("SET @audit_source = ?", ['HighwayStickerAction::invoke']);
                $this->db->executeStatement(
                    "UPDATE users SET highway_sticker_level = ?, highway_sticker_expiry = ? WHERE id = ?",
                    [$level, $expiryDate->format('Y-m-d H:i:s'), $userId]
                );
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }

            $this->db->commit();
            
            $this->session->flash('highway_success', 'Sikeres matrica vásárlás!');
            return $response->withHeader('Location', '/epulet/autopalya')->withStatus(302);

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->session->flash('highway_error', $e->getMessage());
            return $response->withHeader('Location', '/epulet/autopalya')->withStatus(302);
        }
    }
}
