<?php
declare(strict_types=1);

namespace Netmafia\Modules\ECrime\Actions;

use Netmafia\Infrastructure\SessionService;
use Netmafia\Modules\ECrime\Domain\HackService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Domain\Exceptions\GameException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HackDevelopAction
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

        try {
            $result = $this->hackService->developVirus(UserId::of($userId));
            $this->session->flash('hack_result', json_encode($result));
        } catch (\Exception $e) {
            $this->session->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log("HackDevelopAction Error: " . $e->getMessage());
            $this->session->flash('error', 'Hiba történt a fejlesztés során.');
        }

        // PRG minta: visszairányítás a hackelés fülre, hogy a böngésző "frissítése" (F5) biztonságos legyen
        return $response->withHeader('Location', '/e-bunozes/hackeles')->withStatus(302);
    }
}
