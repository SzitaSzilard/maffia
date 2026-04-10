<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Doctrine\DBAL\Connection;

use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class GarageSellSlotAction
{
    private VehicleRepository $vehicleRepository;
    private Connection $db;
    private AuditLogger $logger;
    private MoneyService $moneyService;

    public function __construct(
        VehicleRepository $vehicleRepository, 
        Connection $db,
        AuditLogger $logger,
        MoneyService $moneyService
    ) {
        $this->vehicleRepository = $vehicleRepository;
        $this->db = $db;
        $this->logger = $logger;
        $this->moneyService = $moneyService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $isHtmx = $request->hasHeader('HX-Request');
        
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $this->redirect($response, '/login', $isHtmx);
        }

        $data = $request->getParsedBody();
        $country = $data['country'] ?? null;
        
        if (!$country) {
            $this->logger->log('garage_sell_error', (int)$userId, ['error' => 'country_missing']);
            return $this->redirect($response, '/garazs/bovites', $isHtmx);
        }

        try {
            $this->db->beginTransaction();
            
            // Get current slots
            $currentSlots = $this->vehicleRepository->getPurchasedSlotsForCountry((int)$userId, $country);
            
            if ($currentSlots <= 0) {
                throw new GameException("Nincs eladható slot!");
            }
            
            // Calculate sell price (80% of buy price)
            $pricePerSlot = \Netmafia\Modules\Garage\GarageConfig::SLOT_PRICE_PER_UNIT; 
            $sellPrice = (int)($currentSlots * $pricePerSlot * \Netmafia\Modules\Garage\GarageConfig::SELL_PRICE_RATIO);
            
            // Sell all slots
            $this->vehicleRepository->sellGarageSlots((int)$userId, $country, $currentSlots);
            
            // Add money to user via MoneyService
            $this->moneyService->addMoney(
                UserId::of((int)$userId),
                $sellPrice,
                'sell',
                "Garázs eladás: $currentSlots hely ($country)",
                'garage_slots',
                null
            );
            
            $this->db->commit();
            
            $this->logger->log('garage_sell_success', (int)$userId, [
                'country' => $country,
                'slots' => $currentSlots,
                'price' => $sellPrice
            ]);
            
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $this->logger->log('garage_sell_error', (int)$userId, ['error' => $e->getMessage()]);
        }

        return $this->redirect($response, '/garazs/bovites', $isHtmx);
    }

    private function redirect(Response $response, string $url, bool $isHtmx): Response
    {
        if ($isHtmx) {
            return $response
                ->withHeader('HX-Redirect', $url)
                ->withHeader('HX-Trigger', json_encode(['updateStats' => true]))
                ->withStatus(200);
        }
        
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}
