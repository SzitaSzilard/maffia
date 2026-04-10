<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Garage;

use PHPUnit\Framework\TestCase;
use Netmafia\Modules\Garage\Actions\VehicleDetailsAction;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;

class VehicleDetailsActionTest extends TestCase
{
    private $view;
    private $repository;
    private $action;
    private $request;
    private $response;

    protected function setUp(): void
    {
        $this->view = $this->createMock(Twig::class);
        $this->repository = $this->createMock(VehicleRepository::class);
        $this->action = new VehicleDetailsAction($this->view, $this->repository);
        
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = new Response();
        
        $_SESSION = [];
    }

    public function testNotLoggedInReturns401(): void
    {
        // No session set
        $response = ($this->action)($this->request, $this->response, ['id' => 1]);
        
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testRenderVehicleDetails(): void
    {
        $_SESSION['user_id'] = 1;
        $vehicleId = 1;
        $vehicle = ['id' => 1, 'name' => 'Car', 'user_id' => 1]; // Must include user_id for ownership check

        $this->repository->expects($this->once())
            ->method('getVehicleDetails')
            ->with($vehicleId)
            ->willReturn($vehicle);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/_vehicle_details_modal.twig',
                ['vehicle' => $vehicle]
            )
            ->willReturn($this->response);

        ($this->action)($this->request, $this->response, ['id' => $vehicleId]);
    }

    public function testVehicleNotFound(): void
    {
        $_SESSION['user_id'] = 1;
        $vehicleId = 999;

        $this->repository->expects($this->once())
            ->method('getVehicleDetails')
            ->with($vehicleId)
            ->willReturn(null);

        $response = ($this->action)($this->request, $this->response, ['id' => $vehicleId]);
        
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testVehicleNotOwnedReturns403(): void
    {
        $_SESSION['user_id'] = 1;
        $vehicleId = 5;
        $vehicle = ['id' => 5, 'name' => 'Someone Else Car', 'user_id' => 999]; // Different owner

        $this->repository->expects($this->once())
            ->method('getVehicleDetails')
            ->with($vehicleId)
            ->willReturn($vehicle);

        // View should NOT be called since ownership check fails
        $this->view->expects($this->never())
            ->method('render');

        $response = ($this->action)($this->request, $this->response, ['id' => $vehicleId]);
        
        $this->assertSame(403, $response->getStatusCode());
    }
}
