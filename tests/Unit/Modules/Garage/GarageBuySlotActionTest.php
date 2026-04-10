<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Garage;

use PHPUnit\Framework\TestCase;
use Netmafia\Modules\Garage\Actions\GarageBuySlotAction;
use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Infrastructure\AuditLogger;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Exception;

class GarageBuySlotActionTest extends TestCase
{
    private $garageService;
    private $authService;
    private $logger;
    private $action;
    private $request;
    private $response;

    protected function setUp(): void
    {
        $this->garageService = $this->createMock(GarageService::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->logger = $this->createMock(AuditLogger::class);
        $this->action = new GarageBuySlotAction($this->garageService, $this->authService, $this->logger);
        
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = new Response();
        
        $_SESSION = [];
    }

    public function testNotLoggedInRedirectsAndLogs(): void
    {
        $this->logger->expects($this->once())
            ->method('log')
            ->with('auth_failed', null, ['reason' => 'no_user_id_in_session', 'action' => 'GarageBuySlotAction']);

        $response = ($this->action)($this->request, $this->response);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    // --- Input Validation Tests ---

    public function testMissingSlotsParameter(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn([]);

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_invalid_input', 1, ['error' => 'slots_parameter_missing']);

        $response = ($this->action)($this->request, $this->response);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    public function testSlotsZero(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => 0]);

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_invalid_input', 1, ['error' => 'slots_value_is_0']);

        $response = ($this->action)($this->request, $this->response);
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    public function testSlotsNonNumeric(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => 'five']);

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_invalid_input', 1, ['error' => 'slots_value_is_not_numeric', 'value' => 'five']);

        $response = ($this->action)($this->request, $this->response);
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    public function testInvalidPackageValue(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => 999]);

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_invalid_input', 1, ['error' => 'invalid_package_slots', 'value' => 999]);

        $response = ($this->action)($this->request, $this->response);
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    // --- Valid Packages ---

    /**
     * @dataProvider validPackagesProvider
     */
    public function testSuccessfulPurchasePairs(int $slots, int $cost): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => $slots]);
        
        $this->authService->method('getAuthenticatedUser')->willReturn(['country_code' => 'US']);

        $this->garageService->expects($this->once())
            ->method('buyGarageSlots')
            ->with(1, 'US', $slots, $cost);

        $response = ($this->action)($this->request, $this->response);
        $this->assertSame('/garage', $response->getHeaderLine('Location'));
    }

    public static function validPackagesProvider(): array
    {
        return [
            [5, 700],
            [20, 8000],
            [50, 50000],
            [100, 450000],
        ];
    }

    // --- Service Exception ---

    public function testServiceExceptionLogsError(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => 5]);
        $this->authService->method('getAuthenticatedUser')->willReturn(['country_code' => 'US']);

        $this->garageService->method('buyGarageSlots')->willThrowException(new Exception("DB Down"));

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_action_error', 1, ['exception' => 'DB Down']);

        $response = ($this->action)($this->request, $this->response);
        $this->assertSame('/garage', $response->getHeaderLine('Location'));
    }
}
