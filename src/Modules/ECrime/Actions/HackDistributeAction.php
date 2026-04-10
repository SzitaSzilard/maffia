<?php
declare(strict_types=1);

namespace Netmafia\Modules\ECrime\Actions;

use Netmafia\Infrastructure\SessionService;
use Netmafia\Modules\ECrime\Domain\HackService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HackDistributeAction
{
    private HackService $hackService;
    private SessionService $session;

    public function __construct(HackService $hackService, SessionService $session)
    {
        $this->hackService = $hackService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $parsedBody = $request->getParsedBody();
        $methodId = isset($parsedBody['method']) ? (int) $parsedBody['method'] : 0;

        try {
            if ($methodId <= 0) {
                throw new \Exception('Kérlek válassz egy terjesztési módszert!');
            }

            $result = $this->hackService->distributeVirus(UserId::of($userId), $methodId);
            $this->session->flash('hack_result', json_encode($result));
        } catch (\Exception $e) {
            $this->session->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log("HackDistributeAction Error: " . $e->getMessage());
            $this->session->flash('error', 'Hiba történt a terjesztés során.');
        }

        return $response->withHeader('Location', '/e-bunozes/hackeles')->withStatus(302);
    }
}
