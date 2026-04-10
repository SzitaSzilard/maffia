<?php
declare(strict_types=1);

namespace Netmafia\Modules\Game\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class NewsFormAction
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if (!$user || !$user['is_admin']) {
            return $response->withStatus(403);
        }

        // HTMX Only - returns just the form
        return $this->view->render($response, 'game/news_form.twig');
    }
}
