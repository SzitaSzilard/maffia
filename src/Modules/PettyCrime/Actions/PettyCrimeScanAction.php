<?php

declare(strict_types=1);

namespace Netmafia\Modules\PettyCrime\Actions;

use Netmafia\Modules\PettyCrime\Domain\PettyCrimeService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Exceptions\GameException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Infrastructure\SessionService;

class PettyCrimeScanAction
{
    private PettyCrimeService $pettyCrimeService;
    private SessionService $session;

    public function __construct(PettyCrimeService $pettyCrimeService, SessionService $session)
    {
        $this->pettyCrimeService = $pettyCrimeService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = (int)$request->getAttribute('user_id');

        try {
            $this->pettyCrimeService->scan(UserId::of($userId));
        } catch (GameException $e) {
            $this->session->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('PettyCrimeScanAction Error: ' . $e->getMessage());
            $this->session->flash('error', 'Hiba történt a feltérképezés közben!');
        }

        return $response->withHeader('Location', '/kisstilubunozes')->withStatus(303);
    }
}
