<?php

declare(strict_types=1);

namespace Netmafia\Modules\PettyCrime\Actions;

use Netmafia\Modules\PettyCrime\Domain\PettyCrimeService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Exceptions\GameException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Netmafia\Infrastructure\SessionService;

class PettyCrimeCommitAction
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
        $data = $request->getParsedBody();
        $crimeId = isset($data['crime_id']) ? (int)$data['crime_id'] : 0;

        if ($crimeId <= 0) {
            $this->session->flash('error', 'Érvénytelen választás!');
            return $response->withHeader('Location', '/kisstilubunozes')->withStatus(303);
        }

        try {
            $result = $this->pettyCrimeService->commit(UserId::of($userId), $crimeId);
            $this->session->flash('petty_crime_result', json_encode($result));
        } catch (GameException $e) {
            $this->session->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('PettyCrimeCommitAction Error: ' . $e->getMessage());
            $this->session->flash('error', 'Hiba történt a bűncselekmény végrehajtása közben!');
        }

        return $response->withHeader('Location', '/kisstilubunozes')->withStatus(303);
    }
}
