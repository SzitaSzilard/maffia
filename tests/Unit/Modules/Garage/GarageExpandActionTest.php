<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Garage;

use PHPUnit\Framework\TestCase;
use Netmafia\Modules\Garage\Actions\GarageExpandAction;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Infrastructure\AuditLogger;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

class GarageExpandActionTest extends TestCase
{
    private $view;
    private $authService;
    private $logger;
    private $action;
    private $request;
    private $response;

    protected function setUp(): void
    {
        $this->view = $this->createMock(Twig::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->logger = $this->createMock(AuditLogger::class);
        $this->action = new GarageExpandAction($this->view, $this->authService, $this->logger);
        
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = new Response();
        
        $_SESSION = [];
    }
    
    public function testNotLoggedInLogsError(): void
    {
        $this->logger->expects($this->once())
            ->method('log')
            ->with('auth_failed', null, ['reason' => 'no_user_id_in_session', 'action' => 'GarageExpandAction']);

        $response = ($this->action)($this->request, $this->response);
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testRenderWithPackagesLogsView(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU']; // AuthService returns array, not object

        $this->authService->expects($this->once())
            ->method('getAuthenticatedUser')
            ->willReturn($user);

        $this->request->method('hasHeader')->with('HX-Request')->willReturn(true);

        // Logging Check
        // Note: The implementation I just added calls log with $user->getId(), so verify that
        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_expand_view', 1, ['is_ajax' => true]);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/expand.twig',
                $this->callback(function($data) {
                    return isset($data['packages']) 
                        && count($data['packages']) === 4;
                })
            )
            ->willReturn($this->response);

        ($this->action)($this->request, $this->response);
    }
}
