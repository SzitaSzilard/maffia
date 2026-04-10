<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use DI\Container;

class GarageModuleTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['user_id'] = 1; // Mock logged in user
        $_SESSION['username'] = 'test_user';
        
        $containerDefinitions = require __DIR__ . '/../../config/container.php';
        $container = new Container($containerDefinitions);
        
        AppFactory::setContainer($container);
        $this->app = AppFactory::create();
        
        $routeLoader = require __DIR__ . '/../../config/routes.php';
        $routeLoader($this->app);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id']);
        unset($_SESSION['username']);
    }

    public function testGarageRoutesAccessible(): void
    {
        $routes = [
            '/garazs',
            '/garazs/bovites',
        ];

        foreach ($routes as $path) {
            $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
            $request = $request->withHeader('HX-Request', 'true');
            
            try {
                $response = $this->app->handle($request);
                $status = $response->getStatusCode();
                $this->assertSame(200, $status, "Route $path should return 200, got $status");
            } catch (\Exception $e) {
                // If it fails due to DB or rendering, it's a fail
                $this->fail("Route $path threw exception: " . $e->getMessage());
            }
        }
    }
}
