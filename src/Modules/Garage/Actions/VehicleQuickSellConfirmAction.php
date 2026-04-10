<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Netmafia\Modules\Auth\Domain\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class VehicleQuickSellConfirmAction
{
    private Twig $view;
    private VehicleRepository $repository;

    // Fixed price for Quick Sell
    private const SELL_PRICE = 24500;

    public function __construct(Twig $view, VehicleRepository $repository)
    {
        $this->view = $view;
        $this->repository = $repository;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('user_id');
        if (!$userId) {
            return $response->withStatus(401);
        }

        $vehicleId = (int) $args['id'];
        $vehicle = $this->repository->getVehicleDetails($vehicleId);

        if (!$vehicle || (int)$vehicle['user_id'] !== $userId) {
            $response->getBody()->write('Jármű nem található.');
            return $response->withStatus(404);
        }

        return $this->view->render($response, 'garage/_quick_sell_confirmation_modal.twig', [
            'vehicle' => $vehicle,
            'sell_price' => self::SELL_PRICE
        ]);
    }
}
