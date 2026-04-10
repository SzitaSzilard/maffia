<?php
declare(strict_types=1);

namespace Netmafia\Modules\Buildings\Actions;

use Netmafia\Modules\Buildings\Domain\HospitalService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Modules\Money\Domain\InsufficientBalanceException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HospitalHealAction
{
    private HospitalService $hospitalService;
    // [2026-02-28] FIX: SessionService injektálva — ?error= query param helyett flash message (compliance 5.2 PRG)
    private SessionService $sessionService;

    public function __construct(HospitalService $hospitalService, SessionService $sessionService)
    {
        $this->hospitalService = $hospitalService;
        $this->sessionService = $sessionService;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int)$request->getAttribute('user_id');
        
        $postData = $request->getParsedBody();
        $hospitalId = isset($postData['hospital_id']) ? (int)$postData['hospital_id'] : null;
        
        if (!$hospitalId) {
            // [2026-02-28] FIX: flash + redirect helyett ?error= query param (compliance 5.2)
            $this->sessionService->flash('error', 'Hiányzó kórház azonosító!');
            return $response->withHeader('Location', '/korhaz')->withStatus(303);
        }

        try {
            $this->hospitalService->heal(UserId::of($userId), $hospitalId, 'full');
            $this->sessionService->flash('success', 'Sikeresen meggyógyultál!');
            return $response->withHeader('Location', '/korhaz')->withStatus(303);
            
        } catch (InsufficientBalanceException $e) {
            $this->sessionService->flash('error', 'Nincs elég pénzed a gyógyításhoz!');
            return $response->withHeader('Location', '/korhaz')->withStatus(303);
            
        } catch (\Netmafia\Modules\Money\Domain\InvalidTransactionTypeException $e) {
            error_log("Security Warning: " . $e->getMessage());
            $this->sessionService->flash('error', 'Érvénytelen művelet!');
            return $response->withHeader('Location', '/korhaz')->withStatus(303);

        } catch (\Netmafia\Shared\Exceptions\GameException $e) {
            $this->sessionService->flash('error', $e->getMessage());
            return $response->withHeader('Location', '/korhaz')->withStatus(303);

        } catch (\Throwable $e) {
            error_log("[Hospital Error] User $userId: " . $e->getMessage());
            $this->sessionService->flash('error', 'Hiba történt a gyógyítás során! Kérlek próbáld újra.');
            return $response->withHeader('Location', '/korhaz')->withStatus(303);
        }
    }
}
