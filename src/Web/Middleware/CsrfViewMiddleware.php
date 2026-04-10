<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

class CsrfViewMiddleware implements MiddlewareInterface
{
    private Twig $twig;

    public function __construct(Twig $twig)
    {
        $this->twig = $twig;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $csrf = $request->getAttribute('csrf'); // Assuming 'csrf' might be there, but usually it's separate keys
        
        // Slim CSRF stores tokens in attributes, we need to fetch them using the Guard's keys.
        // But since we don't have the Guard instance here easily without DI, we rely on the standard attribute names 
        // OR we just assume the request attributes are present if Guard ran.
        
        // Note: Slim-Csrf 1.0+ puts the name/value in attributes:
        // $request->getAttribute($nameKey)
        // By default keys are 'csrf_name' and 'csrf_value'.
        
        $nameKey = 'csrf_name';
        $valueKey = 'csrf_value';
        $name = $request->getAttribute($nameKey);
        $value = $request->getAttribute($valueKey);

        if ($name && $value) {
            $this->twig->getEnvironment()->addGlobal('csrf', [
                'keys' => '
                    <input type="hidden" name="' . $nameKey . '" value="' . $name . '">
                    <input type="hidden" name="' . $valueKey . '" value="' . $value . '">
                ',
                'nameKey' => $nameKey,
                'name' => $name,
                'valueKey' => $valueKey,
                'value' => $value
            ]);
        }

        return $handler->handle($request);
    }
}
