<?php
declare(strict_types=1);

namespace Netmafia\Modules\Postal\Actions;

use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PostalClearCartAction
{
    private SessionService $session;

    public function __construct(SessionService $session)
    {
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $this->session->set('postal_cart', []);
        $this->session->flash('postal_success', 'Csomag kiürítve!');
        return $response->withHeader('Location', '/posta')->withStatus(303);
    }
}
