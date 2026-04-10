<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class GarageSellConfirmAction
{
    private VehicleRepository $vehicleRepository;
    private Twig $view;

    public function __construct(
        VehicleRepository $vehicleRepository, 
        Twig $view
    ) {
        $this->vehicleRepository = $vehicleRepository;
        $this->view = $view;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->withStatus(401);
        }

        $params = $request->getQueryParams();
        $country = $params['country'] ?? null;

        if (!$country) {
            $response->getBody()->write('Country missing');
            return $response->withStatus(400);
        }

        // Get slots and calculate price
        $currentSlots = $this->vehicleRepository->getPurchasedSlotsForCountry((int)$userId, $country);
        
        if ($currentSlots <= 0) {
            $response->getBody()->write('Nincs eladható slot!');
            return $response->withStatus(400);
        }

        $pricePerSlot = \Netmafia\Modules\Garage\GarageConfig::SLOT_PRICE_PER_UNIT; 
        $sellPrice = (int)($currentSlots * $pricePerSlot * \Netmafia\Modules\Garage\GarageConfig::SELL_PRICE_RATIO);

        return $this->view->render($response, 'garage/_sell_confirmation_modal.twig', [
            'country' => $country,
            'slots' => $currentSlots,
            'sell_price' => $sellPrice
        ]);
    }
}
