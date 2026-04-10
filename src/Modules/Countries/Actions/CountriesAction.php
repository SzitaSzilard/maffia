<?php
declare(strict_types=1);

namespace Netmafia\Modules\Countries\Actions;

use Netmafia\Modules\Countries\Domain\CountriesService;
use Netmafia\Shared\Domain\ValueObjects\BuildingType;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Netmafia\Modules\Auth\Domain\AuthService;

class CountriesAction
{
    private Twig $view;
    private CountriesService $countriesService;
    private AuthService $authService;

    public function __construct(Twig $view, CountriesService $countriesService, AuthService $authService)
    {
        $this->view = $view;
        $this->countriesService = $countriesService;
        $this->authService = $authService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $user = $userId ? $this->authService->getAuthenticatedUser((int)$userId) : null;

        $viewData = [
            'user' => $user,
            'page_title' => 'Országok',
            'is_ajax' => $request->hasHeader('HX-Request')
        ];
        
        // [2025-12-29 14:30:39] Dinamikus building types BuildingType konstansokból
        // Korábban hardcoded volt minden típus. Most ha új épülettípus jön,
        // automatikusan megjelenik a listában.
        // [2025-12-29 14:37:55] FIX: Helyes angol többes számok
        // 'factory' -> 'factories' (nem 'factorys')
        $buildingTypes = BuildingType::getAll();
        foreach ($buildingTypes as $type) {
            // Típus neve többes számban HELYES angol nyelvtan szerint
            if (str_ends_with($type, 'factory')) {
                $key = str_replace('factory', 'factories', $type);
            } elseif ($type === 'lottery') {
                $key = 'lotteries';
            } else {
                $key = $type . 's';
            }
            
            $viewData[$key] = $this->countriesService->getBuildingList($type);
        }

        return $this->view->render($response, 'countries/index.twig', $viewData);
    }
}
