<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Garage;

use PHPUnit\Framework\TestCase;
use Netmafia\Modules\Garage\Actions\GarageListAction;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Infrastructure\AuditLogger;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Exception;

class GarageListActionTest extends TestCase
{
    private $view;
    private $authService;
    private $repository;
    private $logger;
    private $action;
    private $request;
    private $response;

    protected function setUp(): void
    {
        $this->view = $this->createMock(Twig::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->repository = $this->createMock(VehicleRepository::class);
        $this->logger = $this->createMock(AuditLogger::class);
        $this->action = new GarageListAction($this->view, $this->authService, $this->repository, $this->logger);
        
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = new Response();
        
        $_SESSION = [];
    }

    public function testNotLoggedInRedirectsAndLogs(): void
    {
        $this->logger->expects($this->once())
            ->method('log')
            ->with('auth_failed', null, ['reason' => 'no_user_id_in_session', 'action' => 'GarageListAction']);

        $response = ($this->action)($this->request, $this->response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testRenderLogHtmxRequest(): void
    {
        $_SESSION['user_id'] = 1;
        
        // Setup deps
        $this->authService->method('getAuthenticatedUser')->willReturn(['id' => 1, 'country_code' => 'US']);
        $this->repository->method('getUserVehicles')->willReturn([]);
        $this->repository->method('getGarageCapacity')->willReturn(10);
        
        // Setup HTMX header
        $this->request->method('hasHeader')->with('HX-Request')->willReturn(true);

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_list_viewed', 1, ['mode' => 'htmx', 'country' => 'US']);

        $this->view->method('render')->willReturn($this->response);

        ($this->action)($this->request, $this->response);
    }
    
    public function testCapacityOverflow(): void
    {
        $_SESSION['user_id'] = 1;

        $user = ['id' => 1, 'country_code' => 'US'];
        // 5 vehicles in US
        $vehicles = array_fill(0, 5, ['id' => 1, 'country' => 'US']);
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->repository->method('getUserVehicles')->willReturn($vehicles);

        // Capacity only 3
        $this->repository->method('getGarageCapacity')->willReturn(3);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(), 
                'garage/index.twig', 
                $this->callback(function($data) {
                    // Capacity 3, Used 5 => Free should be 0 (not -2)
                    return $data['free_slots'] === 0;
                })
            )
            ->willReturn($this->response);

        ($this->action)($this->request, $this->response);
    }

    public function testRepositoryExceptionLogged(): void
    {
        $_SESSION['user_id'] = 1;
        $this->authService->method('getAuthenticatedUser')->willReturn(['id' => 1]);
        
        $this->repository->method('getUserVehicles')
            ->willThrowException(new Exception("Database Error"));

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_list_error', 1, ['error' => 'Database Error']);

        $response = ($this->action)($this->request, $this->response);
        $this->assertSame(500, $response->getStatusCode());
    }
}
