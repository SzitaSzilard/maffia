<?php
declare(strict_types=1);

namespace Netmafia\Modules\Postal\Actions;

use Netmafia\Infrastructure\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PostalRemoveFromCartAction
{
    private SessionService $session;

    public function __construct(SessionService $session)
    {
        $this->session = $session;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $index = isset($data['index']) ? (int)$data['index'] : -1;

        $cart = $this->session->get('postal_cart', []);

        if ($index >= 0 && $index < count($cart)) {
            $removedName = $cart[$index]['name'] ?? 'Tétel';
            array_splice($cart, $index, 1);
            $this->session->set('postal_cart', $cart);
            $this->session->flash('postal_success', "{$removedName} eltávolítva a csomagból!");
        }

        return $response->withHeader('Location', '/posta')->withStatus(303);
    }
}
