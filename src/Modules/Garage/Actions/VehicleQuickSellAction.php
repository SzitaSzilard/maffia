<?php
declare(strict_types=1);

namespace Netmafia\Modules\Garage\Actions;

use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class VehicleQuickSellAction
{
    private Twig $view;
    private VehicleRepository $repository;
    private AuthService $authService;
    private MoneyService $moneyService;

    // Fixed price for Quick Sell
    private const SELL_PRICE = 24500;

    public function __construct(
        Twig $view, 
        VehicleRepository $repository, 
        AuthService $authService,
        MoneyService $moneyService
    ) {
        $this->view = $view;
        $this->repository = $repository;
        $this->authService = $authService;
        $this->moneyService = $moneyService;
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
            $response->getBody()->write('Jármű nem található vagy nem a tiéd.');
            return $response->withStatus(404);
        }

        // Logic check: Only 'average' cars can be quick sold
        // Premium categories cannot be quick sold
        $premiumCategories = ['sport', 'suv', 'motor', 'luxury', 'muscle'];
        $isAverage = !in_array($vehicle['category'], $premiumCategories);

        if (!$isAverage) {
            $errorHtml = '<div style="text-align:center; padding: 20px;">
                <h3 style="color: red;">Sikertelen eladás!</h3>
                <p>Csak átlagos járműveket lehet a Bontóban (Gyors eladással) értékesíteni. A prémium autókat a Piacon tudod eladni!</p>
                <button class="menu-btn" onclick="document.getElementById(\'vehicle-modal\').style.display=\'none\'">Bezárás</button>
            </div>';
            $response->getBody()->write($errorHtml);
            return $response->withStatus(200);
        }

        // Perform Sell
        // 1. Give money
        $this->moneyService->addMoney(UserId::of($userId), self::SELL_PRICE, 'sell', "Gyors eladás: {$vehicle['name']}");

        // 2. Delete vehicle
        $this->repository->deleteVehicle($userId, $vehicleId);

        // 3. Responses
        // We need to:
        // a) Close the modal (or show "Eladva" message in modal body)
        // b) Remove the row from the table (OOB)
        // c) Update Money (OOB)

        // Success message for modal body
        $modalBodyHtml = '<div style="text-align:center; padding: 20px;">
            <h3 style="color: green;">Sikeres eladás!</h3>
            <p>A járművet eladtad $' . number_format(self::SELL_PRICE) . '-ért.</p>
            <button onclick="document.getElementById(\'vehicle-modal\').style.display=\'none\'">Bezárás</button>
        </div>';

        $response->getBody()->write(
            $modalBodyHtml . 
            '<tr id="vehicle-row-' . $vehicleId . '" hx-swap-oob="delete"></tr>' // OOB delete row
        );

        return $response->withHeader('HX-Trigger', json_encode(['updateStats' => true]));
    }
}
