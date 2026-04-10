<?php
declare(strict_types=1);

namespace Netmafia\Modules\OrganizedCrime\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService;
use Slim\Views\Twig;

class OrganizedCrimeExecutionAction
{
    private OrganizedCrimeService $crimeService;
    private Twig $twig;

    public function __construct(OrganizedCrimeService $crimeService, Twig $twig)
    {
        $this->crimeService = $crimeService;
        $this->twig = $twig;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        $activeCrime = $this->crimeService->getActiveCrimeForUser($userId);
        
        $viewData = [
            'crime' => $activeCrime,
            'is_organizer' => true,
            'session_user_id' => $userId
        ];

        if (!$activeCrime || $activeCrime['leader_id'] !== $userId) {
            $viewData['global_error'] = 'Nem te vagy a szervező vagy nincs aktív bűnözésed!';
            return $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
        }

        try {
            // Elindítjuk a bűnözést
            $res = $this->crimeService->startCrime($userId, (int)$activeCrime['id']);
            
            if (!$res['success']) {
                $viewData['global_error'] = $res['error'];
            } else {
                $isHeistSuccess = $res['is_heist_success'] ?? true;
                if ($isHeistSuccess) {
                    $viewData['global_success'] = '✅ A rablás SIKERES volt! A részleteket üzenetben küldtük el!';
                } else {
                    $viewData['global_error'] = '❌ A rablás ELBUKOTT! A részleteket üzenetben küldtük el.';
                }
                // Töröljük a crime-t a nézetből hogy új form jelenjen meg
                $viewData['crime'] = null; 
            }
        } catch (\Throwable $e) {
            $viewData['global_error'] = 'Kritikus hiba: ' . $e->getMessage();
        }

        $rendered = $this->twig->render($response, 'game/organized_crime/_squad.twig', $viewData);
        
        // Stat sáv automatikus frissítése (pénz, XP, energia, élet)
        if (!empty($res['success'])) {
            $rendered = $rendered->withHeader('HX-Trigger', json_encode(['updateStats' => true]));
        }
        
        return $rendered;
    }
}
