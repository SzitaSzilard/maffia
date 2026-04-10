<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Views\Twig;

class HtmxMiddleware implements MiddlewareInterface
{
    private Twig $twig;

    public function __construct(Twig $twig)
    {
        $this->twig = $twig;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        // Check for HTMX request header
        $isHtmx = $request->hasHeader('HX-Request');

        // Determine layout file
        $layout = $isHtmx ? 'layouts/ajax.twig' : 'layouts/base_game.twig';

        // Add 'layout' global variable to Twig
        $this->twig->getEnvironment()->addGlobal('layout', $layout);
        $this->twig->getEnvironment()->addGlobal('is_htmx', $isHtmx);
        $this->twig->getEnvironment()->addGlobal('is_ajax', $isHtmx); // Backward compatibility

        return $handler->handle($request);
    }
}
