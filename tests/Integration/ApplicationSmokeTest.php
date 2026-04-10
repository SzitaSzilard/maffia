<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use DI\Container;

/**
 * Smoke Test - Ellenőrzi, hogy az alkalmazás elindul és a route-ok betöltődnek
 */
class ApplicationSmokeTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        // Suppress session_start warnings in test environment
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['user_id'] = 1; // Mock logged in user
        $_SESSION['username'] = 'test_user';
        
        // Build container
        $containerDefinitions = require __DIR__ . '/../../config/container.php';
        $container = new Container($containerDefinitions);
        
        // Create app
        AppFactory::setContainer($container);
        $this->app = AppFactory::create();
        
        // Load routes - this is what we're really testing!
        $routeLoader = require __DIR__ . '/../../config/routes.php';
        $routeLoader($this->app);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id']);
        unset($_SESSION['username']);
    }

    /**
     * Test that routes can be loaded without errors
     */
    public function testRoutesCanBeLoaded(): void
    {
        // If we get here without exception, routes loaded successfully
        $this->assertTrue(true, 'Routes loaded without exception');
    }

    /**
     * Test that all middleware can be instantiated
     */
    public function testMiddlewareInstantiation(): void
    {
        $container = $this->app->getContainer();
        
        // Test each middleware can be built from container
        $services = [
            \Netmafia\Infrastructure\CacheService::class,
            \Netmafia\Infrastructure\SessionService::class,
            \Netmafia\Modules\Messages\Domain\MessageService::class,
            \Netmafia\Modules\Notifications\Domain\NotificationService::class,
            \Slim\Views\Twig::class,
        ];
        
        foreach ($services as $service) {
            $instance = $container->get($service);
            $this->assertNotNull($instance, "Failed to instantiate: $service");
        }
    }

    /**
     * Test public routes return valid responses
     */
    public function testPublicRoutesAccessible(): void
    {
        $publicRoutes = [
            '/login',
            '/register',
        ];

        foreach ($publicRoutes as $path) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
            
            try {
                $response = $this->app->handle($request);
                $status = $response->getStatusCode();
                // 200 = success, 302/303 = redirect (e.g. already logged in)
                $this->assertTrue(
                    in_array($status, [200, 302, 303]),
                    "Route $path should return 200 or redirect, got $status"
                );
            } catch (\Exception $e) {
                $this->fail("Route $path threw exception: " . $e->getMessage());
            }
        }
    }

    /**
     * Test protected routes exist (may redirect to login)
     */
    public function testProtectedRoutesExist(): void
    {
        $protectedRoutes = [
            '/game',
            '/uzenetek',
            '/ertesitesek',
            '/kocsma',
        ];

        foreach ($protectedRoutes as $path) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
            $request = $request->withHeader('HX-Request', 'true'); // Simulate HTMX
            
            try {
                $response = $this->app->handle($request);
                // Should return 200 (success) or 302/303 (redirect to login)
                $status = $response->getStatusCode();
                $this->assertTrue(
                    in_array($status, [200, 302, 303]),
                    "Route $path should return 200 or redirect, got $status"
                );
            } catch (\Exception $e) {
                $this->fail("Route $path threw exception: " . $e->getMessage());
            }
        }
    }
}
