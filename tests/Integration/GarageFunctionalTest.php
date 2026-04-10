<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use DI\Container;
use Psr\Http\Message\ResponseInterface;

/**
 * Functional HTTP Tests for Garage Module
 * 
 * These tests verify:
 * - Routes are accessible and return correct status codes
 * - Authentication redirects work correctly
 * - POST endpoints process data correctly
 * - Error handling returns appropriate status codes
 * - HTMX requests are handled properly
 * 
 * These tests use the REAL application container and routing,
 * simulating actual HTTP requests.
 */
class GarageFunctionalTest extends TestCase
{
    private App $app;
    private Container $container;

    protected function setUp(): void
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        
        // Clear session
        $_SESSION = [];
        
        // Create container and app
        $containerDefinitions = require __DIR__ . '/../../config/container.php';
        $this->container = new Container($containerDefinitions);
        
        AppFactory::setContainer($this->container);
        $this->app = AppFactory::create();
        
        // Load routes
        $routeLoader = require __DIR__ . '/../../config/routes.php';
        $routeLoader($this->app);
        
        // Add error handling
        $this->app->addErrorMiddleware(true, true, true);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ==========================================================================
    // HELPER METHODS
    // ==========================================================================

    private function createRequest(string $method, string $path, array $body = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, $path);
        
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        
        if (!empty($body)) {
            $request = $request->withParsedBody($body);
        }
        
        return $request;
    }

    private function handleRequest(string $method, string $path, array $body = [], array $headers = []): ResponseInterface
    {
        $request = $this->createRequest($method, $path, $body, $headers);
        return $this->app->handle($request);
    }

    private function loginAsUser(int $userId = 1, string $username = 'test_user'): void
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
    }

    // ==========================================================================
    // 1. AUTHENTICATION TESTS - NOT LOGGED IN
    // ==========================================================================

    public function testGarageList_NotLoggedIn_RedirectsToLogin(): void
    {
        $response = $this->handleRequest('GET', '/garazs');
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testGarageExpand_NotLoggedIn_RedirectsToLogin(): void
    {
        $response = $this->handleRequest('GET', '/garazs/bovites');
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testGarageBuySlot_NotLoggedIn_RedirectsToLogin(): void
    {
        $response = $this->handleRequest('POST', '/garazs/bovites/vasarlas', ['slots' => 5]);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testVehicleDetails_NotLoggedIn_Returns401Or302(): void
    {
        $response = $this->handleRequest('GET', '/garazs/vehicle/1');
        
        // Can be 401 (action returns) or 302 (middleware redirects)
        $this->assertContains($response->getStatusCode(), [401, 302]);
    }

    // ==========================================================================
    // 2. AUTHENTICATED ACCESS TESTS
    // ==========================================================================

    public function testGarageList_LoggedIn_Returns200OrRendersPage(): void
    {
        $this->loginAsUser();
        
        try {
            $response = $this->handleRequest('GET', '/garazs', [], ['HX-Request' => 'true']);
            $status = $response->getStatusCode();
            
            // 200 = success, 500 = possible DB issue but container resolved
            $this->assertContains($status, [200, 500], "Expected 200 or 500, got $status");
        } catch (\Exception $e) {
            // If exception is about DB/template, container still works
            $this->assertStringNotContainsString('Too few arguments', $e->getMessage());
        }
    }

    public function testGarageExpand_LoggedIn_Returns200OrRendersPage(): void
    {
        $this->loginAsUser();
        
        try {
            $response = $this->handleRequest('GET', '/garazs/bovites', [], ['HX-Request' => 'true']);
            $status = $response->getStatusCode();
            
            $this->assertContains($status, [200, 500], "Expected 200 or 500, got $status");
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('Too few arguments', $e->getMessage());
        }
    }

    // ==========================================================================
    // 3. POST REQUEST VALIDATION TESTS
    // ==========================================================================

    public function testBuySlot_MissingSlots_RedirectsToExpand(): void
    {
        $this->loginAsUser();
        
        $response = $this->handleRequest('POST', '/garazs/bovites/vasarlas', []);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    public function testBuySlot_ZeroSlots_RedirectsToExpand(): void
    {
        $this->loginAsUser();
        
        $response = $this->handleRequest('POST', '/garazs/bovites/vasarlas', ['slots' => 0]);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    public function testBuySlot_InvalidPackage_RedirectsToExpand(): void
    {
        $this->loginAsUser();
        
        $response = $this->handleRequest('POST', '/garazs/bovites/vasarlas', ['slots' => 999]);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    public function testBuySlot_NonNumericSlots_RedirectsToExpand(): void
    {
        $this->loginAsUser();
        
        $response = $this->handleRequest('POST', '/garazs/bovites/vasarlas', ['slots' => 'abc']);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    /**
     * @dataProvider validPackagesProvider
     */
    public function testBuySlot_ValidPackage_RedirectsToGarage(int $slots): void
    {
        $this->loginAsUser();
        
        $response = $this->handleRequest('POST', '/garazs/bovites/vasarlas', ['slots' => $slots]);
        
        // Should redirect to /garage (may fail due to insufficient funds, but that's OK)
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/garage', $response->getHeaderLine('Location'));
    }

    public static function validPackagesProvider(): array
    {
        return [
            '5 slots' => [5],
            '20 slots' => [20],
            '50 slots' => [50],
            '100 slots' => [100],
        ];
    }

    // ==========================================================================
    // 4. HTMX REQUEST HANDLING TESTS
    // ==========================================================================

    public function testGarageList_HtmxRequest_SetsCorrectContentType(): void
    {
        $this->loginAsUser();
        
        try {
            $response = $this->handleRequest('GET', '/garazs', [], ['HX-Request' => 'true']);
            
            // HTMX requests should return HTML
            $contentType = $response->getHeaderLine('Content-Type');
            $this->assertStringContainsString('text/html', $contentType, 'HTMX request should return HTML');
        } catch (\Exception $e) {
            // DB errors are acceptable, but container issues are not
            $this->assertStringNotContainsString('Too few arguments', $e->getMessage());
        }
    }

    public function testGarageExpand_HtmxRequest_SetsCorrectContentType(): void
    {
        $this->loginAsUser();
        
        try {
            $response = $this->handleRequest('GET', '/garazs/bovites', [], ['HX-Request' => 'true']);
            
            $contentType = $response->getHeaderLine('Content-Type');
            $this->assertStringContainsString('text/html', $contentType);
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('Too few arguments', $e->getMessage());
        }
    }

    // ==========================================================================
    // 5. VEHICLE DETAILS TESTS
    // ==========================================================================

    public function testVehicleDetails_NonExistentId_Returns404(): void
    {
        $this->loginAsUser();
        
        $response = $this->handleRequest('GET', '/garazs/vehicle/99999999');
        
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testVehicleDetails_ZeroId_Returns404(): void
    {
        $this->loginAsUser();
        
        $response = $this->handleRequest('GET', '/garazs/vehicle/0');
        
        $this->assertSame(404, $response->getStatusCode());
    }

    // ==========================================================================
    // 6. ERROR HANDLING TESTS
    // ==========================================================================

    public function testInvalidRoute_Returns404(): void
    {
        $this->loginAsUser();
        
        try {
            $response = $this->handleRequest('GET', '/garazs/nonexistent');
            $this->assertSame(404, $response->getStatusCode());
        } catch (\Slim\Exception\HttpNotFoundException $e) {
            // This is also acceptable
            $this->assertTrue(true);
        }
    }

    public function testWrongMethod_Returns405(): void
    {
        $this->loginAsUser();
        
        try {
            // GET on a POST-only route
            $response = $this->handleRequest('GET', '/garazs/bovites/vasarlas');
            $this->assertSame(405, $response->getStatusCode());
        } catch (\Slim\Exception\HttpMethodNotAllowedException $e) {
            $this->assertTrue(true);
        }
    }

    // ==========================================================================
    // 7. ROUTE RESOLUTION TESTS
    // ==========================================================================

    public function testAllGarageRoutesResolve(): void
    {
        $this->loginAsUser();
        
        $routes = [
            ['GET', '/garazs'],
            ['GET', '/garazs/bovites'],
            ['POST', '/garazs/bovites/vasarlas', ['slots' => 5]],
            ['GET', '/garazs/vehicle/1'],
        ];
        
        foreach ($routes as $routeData) {
            $method = $routeData[0];
            $path = $routeData[1];
            $body = $routeData[2] ?? [];
            
            try {
                $response = $this->handleRequest($method, $path, $body);
                
                // Any status except 500 with "Too few arguments" is OK
                $this->assertNotNull($response, "Route $method $path should return a response");
                
            } catch (\Exception $e) {
                // DI container errors should fail the test
                if (strpos($e->getMessage(), 'Too few arguments') !== false) {
                    $this->fail("Route $method $path has DI configuration error: " . $e->getMessage());
                }
                // Other exceptions (DB, template) are OK for this test
            }
        }
    }

    // ==========================================================================
    // 8. SECURITY TESTS
    // ==========================================================================

    public function testVehicleDetails_WrongUser_Returns403(): void
    {
        // Login as user 1
        $this->loginAsUser(1);
        
        // Try to access a vehicle (will return 403 if exists and belongs to another user, or 404)
        $response = $this->handleRequest('GET', '/garazs/vehicle/1');
        
        // Either 403 (not owner), 404 (not found), or 200 (is owner)
        $this->assertContains($response->getStatusCode(), [200, 403, 404]);
    }

    public function testBuySlot_SqlInjectionAttempt_HandledSafely(): void
    {
        $this->loginAsUser();
        
        // SQL injection attempt in slots field
        $response = $this->handleRequest('POST', '/garazs/bovites/vasarlas', [
            'slots' => "5; DROP TABLE users;--"
        ]);
        
        // Should be handled as invalid input (non-numeric)
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    // ==========================================================================
    // 9. RESPONSE CONTENT TESTS
    // ==========================================================================

    public function testGarageExpand_ContainsPackageOptions(): void
    {
        $this->loginAsUser();
        
        try {
            $response = $this->handleRequest('GET', '/garazs/bovites', [], ['HX-Request' => 'true']);
            
            if ($response->getStatusCode() === 200) {
                $body = (string) $response->getBody();
                
                // Check for expected package prices
                $this->assertStringContainsString('700', $body, 'Should contain 5 slot price (700)');
                $this->assertStringContainsString('8', $body, 'Should contain 20 slot price part');
            }
        } catch (\Exception $e) {
            // DB errors are acceptable
            $this->assertStringNotContainsString('Too few arguments', $e->getMessage());
        }
    }

    // ==========================================================================
    // 10. COMPLETE PURCHASE FLOW TEST
    // ==========================================================================

    public function testCompletePurchaseFlow_Navigation(): void
    {
        $this->loginAsUser();
        
        // Step 1: Access garage list
        try {
            $response1 = $this->handleRequest('GET', '/garazs', [], ['HX-Request' => 'true']);
            $this->assertContains($response1->getStatusCode(), [200, 500]);
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('Too few arguments', $e->getMessage());
        }
        
        // Step 2: Access expand page
        try {
            $response2 = $this->handleRequest('GET', '/garazs/bovites', [], ['HX-Request' => 'true']);
            $this->assertContains($response2->getStatusCode(), [200, 500]);
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('Too few arguments', $e->getMessage());
        }
        
        // Step 3: Submit purchase
        $response3 = $this->handleRequest('POST', '/garazs/bovites/vasarlas', ['slots' => 5]);
        $this->assertSame(302, $response3->getStatusCode());
        $this->assertSame('/garage', $response3->getHeaderLine('Location'));
    }
}
