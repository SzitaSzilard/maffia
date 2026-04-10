<?php
declare(strict_types=1);

use Slim\App;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Slim\Csrf\Guard;

return function (App $app) {
    // Start Session explicitly here because Guard requires it immediately
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $settings = [
            'name' => 'netmafia_sess',
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => isset($_SERVER['HTTPS']),
            'use_strict_mode' => true,
        ];
        session_start($settings);
    }

    // Parse JSON Body
    $app->addBodyParsingMiddleware();

    // Twig Middleware (for rendering views)
    $app->add(TwigMiddleware::createFromContainer($app, Twig::class));

    // Csrf View Middleware (Injects tokens into Twig) - Must be "inner" to Guard
    $app->add(new \Netmafia\Web\Middleware\CsrfViewMiddleware($app->getContainer()->get(Twig::class)));

    // HTMX Middleware (Detects HX-Request and sets global is_ajax/layout vars)
    $app->add(new \Netmafia\Web\Middleware\HtmxMiddleware($app->getContainer()->get(Twig::class)));

    // Flash Middleware (Injects session flashes into Twig)
    $app->add(new \Netmafia\Web\Middleware\FlashMiddleware(
        $app->getContainer()->get(Twig::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\SessionService::class)
    ));

    // CSRF Protection (Guard runs first, generates tokens)
    $guard = new Guard($app->getResponseFactory());
    $guard->setPersistentTokenMode(true);
    
    // CSRF Failure Handler for HTMX
    $guard->setFailureHandler(function ($request, $handler) use ($app) {
        $response = $app->getResponseFactory()->createResponse();
        $isHtmx = $request->hasHeader('HX-Request');
        
        $message = 'Biztonsági hiba (Lejárt munkamenet). Kérlek frissítsd az oldalt!';
        
        if ($isHtmx) {
            return $response
                ->withStatus(200) // Return 200 so HTMX processes the trigger
                ->withHeader('HX-Trigger', json_encode([
                    'notification' => [
                        'type' => 'error',
                        'message' => $message
                    ]
                ]));
        }
        
        // Fallback for non-HTMX
        $response->getBody()->write($message);
        return $response->withStatus(400);
    });
    
    $app->add($guard);

    // Rate Limiting (brute force / spam védelem)
    $app->add(new \Netmafia\Web\Middleware\RateLimitMiddleware());

    // Security Headers (applies to ALL responses)
    $app->add(new \Netmafia\Web\Middleware\SecurityHeadersMiddleware());

    // Session Timeout Middleware (15 minutes of inactivity logout)
    $app->add(new \Netmafia\Web\Middleware\SessionTimeoutMiddleware(
        $app->getContainer()->get(\Netmafia\Infrastructure\SessionService::class),
        15, // 15 minutes timeout
        $app->getContainer()->get(\Netmafia\Infrastructure\AuditLogger::class)
    ));

    // Last Activity Middleware (DB-szintű last_activity frissítés, throttled 60 mp-enként)
    $app->add(new \Netmafia\Web\Middleware\LastActivityMiddleware(
        $app->getContainer()->get(\Doctrine\DBAL\Connection::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\SessionService::class)
    ));

    // Routing Middleware
    $app->addRoutingMiddleware();

    // Error Middleware with Custom Handler
    $debug = (bool)($_ENV['APP_DEBUG'] ?? false);
    $errorMiddleware = $app->addErrorMiddleware($debug, true, true);
    
    // Custom Error Handler for styled 404/500 pages
    $errorHandler = new \Netmafia\Web\Handler\ErrorHandler(
        $app->getResponseFactory(),
        $app->getContainer()->get(Twig::class)
    );
    $errorMiddleware->setDefaultErrorHandler($errorHandler);
};
