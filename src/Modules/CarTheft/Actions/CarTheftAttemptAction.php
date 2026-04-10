<?php
declare(strict_types=1);

namespace Netmafia\Modules\CarTheft\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Modules\CarTheft\Domain\CarTheftService;

class CarTheftAttemptAction
{
    private CarTheftService $theftService;
    private SessionService $session;

    public function __construct(CarTheftService $theftService, SessionService $session)
    {
        $this->theftService = $theftService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user_id');
        $data = $request->getParsedBody();
        $targetVehicleId = (int) ($data['vehicle_id'] ?? 0);

        if ($targetVehicleId <= 0) {
            $this->session->flash('error', 'Érvénytelen jármű azonosító!');
            return $response->withHeader('Location', '/autolopas/utca')->withStatus(303);
        }

        try {
            $result = $this->theftService->attemptTheft(UserId::of($userId), $targetVehicleId);
            
            $flashType = $result['success'] ? 'success' : 'error';
            $msg = $result['message'];
            $this->session->flash($flashType, $msg);
            
            return $response->withHeader('Location', '/autolopas/utca')->withStatus(303);

        } catch (GameException $e) {
            $this->session->flash('error', $e->getMessage());
            return $response->withHeader('Location', '/autolopas/utca')->withStatus(303);
        }
    }
}
