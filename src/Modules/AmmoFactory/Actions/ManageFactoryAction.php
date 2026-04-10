<?php
declare(strict_types=1);

namespace Netmafia\Modules\AmmoFactory\Actions;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\AmmoFactory\Domain\AmmoFactoryService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Buildings\Domain\BuildingService;

class ManageFactoryAction
{
    private Twig $view;
    private AmmoFactoryService $ammoFactoryService;
    private AuthService $authService;
    private BuildingService $buildingService;
    private Connection $db;

    public function __construct(
        Twig $view,
        AmmoFactoryService $ammoFactoryService,
        AuthService $authService,
        BuildingService $buildingService,
        Connection $db
    ) {
        $this->view = $view;
        $this->ammoFactoryService = $ammoFactoryService;
        $this->authService = $authService;
        $this->buildingService = $buildingService;
        $this->db = $db;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        $user = $this->authService->getAuthenticatedUser($userId);

        // Find Factory (Owner check implied by ownership logic later)
        // Or pass building_id via route argument?
        // Let's assume we find the one owned by this user?
        // Or generic 'manage factory' route which looks up owned factory.
        
        $buildingId = $this->db->fetchOne("SELECT id FROM buildings WHERE type='ammo_factory' AND owner_id = ? LIMIT 1", [$userId]);
        
        if (!$buildingId) {
            // Try fetch by name if type column not reliable yet
            $buildingId = $this->db->fetchOne("SELECT id FROM buildings WHERE name = 'Töltény gyár' AND owner_id = ? LIMIT 1", [$userId]);
        }

        if (!$buildingId) {
             // Not an owner of any ammo factory
             return $response->withHeader('Location', '/toltenygyar')->withStatus(302);
        }

        $factory = $this->ammoFactoryService->getFactoryData((int)$buildingId);

        // Handle HTMX Polling for Status
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['view']) && $queryParams['view'] === 'status') {
            return $this->view->render($response, 'ammo_factory/partials/status.twig', [
                'factory' => $factory
            ]);
        }

        // Handle Post
        // Handle Post
        if ($request->getMethod() === 'POST') {
            $data = $request->getParsedBody();
            try {
                // 1. Termelés Indítása
                if (isset($data['start_production'])) {
                    $investment = (int)$data['investment'];
                    $this->ammoFactoryService->startProduction((int)$buildingId, $userId, $investment);
                    $success = "Termelés elindítva!";
                } 
                // 2. Ár Frissítése
                elseif (isset($data['update_price'])) {
                    $newPrice = (int)$data['price'];
                    $this->ammoFactoryService->updatePrice((int)$buildingId, $userId, $newPrice);
                    $success = "Ár frissítve!";
                }
                // 3. Egyéb Beállítások (Jutalék, Kifizetés) - BuildingService kezeli
                elseif (isset($data['update_settings'])) {
                    $payoutMode = $data['payout_mode'] ?? 'instant';

                    $this->buildingService->setPayoutMode((int)$buildingId, $userId, $payoutMode);

                    $success = "Beállítások mentve!";
                }
                
                // Refresh data
                $factory = $this->ammoFactoryService->getFactoryData((int)$buildingId);
                
                // Refresh User Data (Money spent)
                $user = $this->authService->getAuthenticatedUser($userId);

            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->view->render($response, 'ammo_factory/manage.twig', [
            'user' => $user,
            'factory' => $factory,
            'success' => $success ?? null,
            'error' => $error ?? null
        ]);
    }
}
