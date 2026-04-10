<?php
declare(strict_types=1);

namespace Netmafia\Modules\Hack\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class HackIndexAction
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function __invoke(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'hack/index.twig', [
            'virus_progress' => 0,
            'zombie_count' => 0,
            'is_ajax' => $request->hasHeader('HX-Request')
        ]);
    }
}
