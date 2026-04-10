<?php
declare(strict_types=1);

namespace Netmafia\Modules\Kocsma\Actions;

use Netmafia\Modules\Kocsma\Domain\KocsmaService;
use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class KocsmaChatAction
{
    private KocsmaService $service;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;

    public function __construct(KocsmaService $service, SessionService $session)
    {
        $this->service = $service;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $message = $data['message'] ?? '';

        if (empty(trim($message))) {
            return $response->withHeader('Location', '/kocsma')->withStatus(302);
        }

        $userId = $request->getAttribute('user_id');
        // [2026-02-28] FIX: SessionService getUsername() használata $_SESSION helyett
        $username = $this->session->getUsername() ?? 'Ismeretlen';

        $this->service->postMessage((int)$userId, $username, $message);

        // Redirect back to Kocsma
        return $response->withHeader('Location', '/kocsma')->withStatus(302);
    }
}
