<?php
declare(strict_types=1);

namespace Netmafia\Modules\Postal\Actions;

use Netmafia\Modules\Postal\Domain\PostalService;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Shared\Exceptions\GameException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PostalCancelAction
{
    private PostalService $postalService;
    private SessionService $session;

    public function __construct(PostalService $postalService, SessionService $session)
    {
        $this->postalService = $postalService;
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $packageId = (int)($args['id'] ?? 0);

        if ($packageId <= 0) {
            $this->session->flash('postal_error', 'Érvénytelen csomag azonosító!');
            return $response->withHeader('Location', '/posta')->withStatus(302);
        }

        try {
            $this->postalService->cancelPackage((int)$user['id'], $packageId);
            $this->session->flash('postal_success', 'A csomagot sikeresen visszavontad. A tételeket (és a postaköltséget) visszakaptad!');
        } catch (GameException $e) {
            $this->session->flash('postal_error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Postal Cancel Error: ' . $e->getMessage());
            $this->session->flash('postal_error', 'Váratlan hiba történt a csomag visszavonása közben.');
        }

        return $response->withHeader('Location', '/posta')->withStatus(302);
    }
}
