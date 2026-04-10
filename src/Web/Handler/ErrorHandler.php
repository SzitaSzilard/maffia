<?php
declare(strict_types=1);

namespace Netmafia\Web\Handler;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Views\Twig;

/**
 * Custom Error Handler - Szép hibaoldalak renderelése
 */
class ErrorHandler implements ErrorHandlerInterface
{
    private ResponseFactoryInterface $responseFactory;
    private Twig $twig;

    public function __construct(ResponseFactoryInterface $responseFactory, Twig $twig)
    {
        $this->responseFactory = $responseFactory;
        $this->twig = $twig;
    }

    public function __invoke(
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        // Logolás
        if ($logErrors) {
            error_log($this->getErrorDescription($exception));
        }

        // Státuszkód meghatározása
        $statusCode = $this->getStatusCodeFromException($exception);
        
        // Response létrehozása
        $response = $this->responseFactory->createResponse($statusCode);
        
        // Template kiválasztása
        $template = $this->getTemplateForStatusCode($statusCode);
        
        try {
            return $this->twig->render($response, $template, [
                'path' => $request->getUri()->getPath(),
                'message' => $exception->getMessage(),
                'debug' => $displayErrorDetails,
                'code' => $statusCode,
            ]);
        } catch (\Throwable $e) {
            // Ha a template renderelés sikertelen, egyszerű HTML
            $response->getBody()->write(
                '<h1>Error ' . $statusCode . '</h1><p>' . htmlspecialchars($exception->getMessage()) . '</p>'
            );
            return $response;
        }
    }

    /**
     * Státuszkód meghatározása a kivétel típusa alapján
     */
    private function getStatusCodeFromException(\Throwable $exception): int
    {
        if ($exception instanceof HttpNotFoundException) {
            return 404;
        }
        
        if ($exception instanceof \Slim\Exception\HttpMethodNotAllowedException) {
            return 405;
        }
        
        if ($exception instanceof \Slim\Exception\HttpForbiddenException) {
            return 403;
        }
        
        if ($exception instanceof \Slim\Exception\HttpUnauthorizedException) {
            return 401;
        }
        
        if ($exception instanceof \Slim\Exception\HttpException) {
            return $exception->getCode();
        }
        
        return 500;
    }

    /**
     * Template fájl kiválasztása
     */
    private function getTemplateForStatusCode(int $code): string
    {
        $templates = [
            404 => 'errors/404.twig',
            500 => 'errors/500.twig',
        ];
        
        return $templates[$code] ?? 'errors/500.twig';
    }

    /**
     * Hiba leírása logoláshoz
     */
    private function getErrorDescription(\Throwable $exception): string
    {
        return sprintf(
            "[%s] %s in %s:%d",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );
    }
}
