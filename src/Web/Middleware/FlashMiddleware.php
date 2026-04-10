<?php
declare(strict_types=1);

namespace Netmafia\Web\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Views\Twig;
use Netmafia\Infrastructure\SessionService;

class FlashMiddleware implements MiddlewareInterface
{
    private Twig $twig;
    private SessionService $session;

    public function __construct(Twig $twig, SessionService $session)
    {
        $this->twig = $twig;
        $this->session = $session;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $isHtmx = $request->hasHeader('HX-Request');

        if (!$isHtmx) {
            // FULL PAGE LOAD
            $flashes = $this->session->getAllFlashes();
            $safeFlashes = [];
            
            foreach ($flashes as $type => $message) {
                // Skips JSON payloads & system flashes and restores them to session 
                // so specific module Actions can fetch them later in the request chain.
                $isJson = is_string($message) && (substr(trim($message), 0, 1) === '{' || substr(trim($message), 0, 1) === '[');
                if ($isJson || str_ends_with((string)$type, '_result') || $type === 'last_username' || $type === 'last_email') {
                    $this->session->flash($type, $message);
                } else {
                    $safeFlashes[$type] = $message;
                }
            }

            $this->twig->getEnvironment()->addGlobal('session_flashes', $safeFlashes);
            return $handler->handle($request);
        }

        // HTMX REQUEST
        $response = $handler->handle($request);
        
        // If the response is a redirect, PRG pattern dictates flashes stay in session
        if ($response->hasHeader('Location') || $response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
            return $response;
        }

        $flashes = $this->session->getAllFlashes();
        if (!empty($flashes)) {
            $existingTrigger = $response->getHeaderLine('HX-Trigger');
            $triggers = empty($existingTrigger) ? [] : json_decode($existingTrigger, true) ?? [$existingTrigger => true];

            foreach ($flashes as $type => $message) {
                $isJson = is_string($message) && (substr(trim($message), 0, 1) === '{' || substr(trim($message), 0, 1) === '[');
                if ($isJson || str_ends_with((string)$type, '_result') || $type === 'last_username' || $type === 'last_email') {
                    $this->session->flash($type, $message);
                    continue;
                }

                $normalizedType = str_contains((string)$type, 'error') ? 'error' : (str_contains((string)$type, 'success') ? 'success' : $type);
                // Error always wins — don't let a stale success overwrite a fresh error
                if (empty($triggers['showFlash']) || $normalizedType === 'error') {
                    $triggers['showFlash'] = ['type' => $normalizedType, 'message' => $message];
                }
            }

            if (!empty($triggers['showFlash']) || count($triggers) > (empty($existingTrigger) ? 0 : 1)) {
                return $response->withHeader('HX-Trigger', json_encode($triggers));
            }
        }

        return $response;
    }
}
