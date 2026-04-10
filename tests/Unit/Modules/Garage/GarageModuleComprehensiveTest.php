<?php
declare(strict_types=1);

namespace Tests\Unit\Modules\Garage;

use PHPUnit\Framework\TestCase;
use Netmafia\Modules\Garage\Actions\GarageBuySlotAction;
use Netmafia\Modules\Garage\Actions\GarageExpandAction;
use Netmafia\Modules\Garage\Actions\GarageListAction;
use Netmafia\Modules\Garage\Actions\VehicleDetailsAction;
use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Infrastructure\AuditLogger;
use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Exception;

/**
 * Comprehensive Garage Module Test Suite
 * 
 * This test file covers:
 * - Authentication tests (~5)
 * - Input validation tests (~15)
 * - Business logic tests (~20)
 * - Database operations tests (~25)
 * - Exception handling tests (~10)
 * - Edge cases tests (~10)
 * - Integration tests (~5)
 * 
 * Total: ~90 test cases
 */
class GarageModuleComprehensiveTest extends TestCase
{
    // ==========================================================================
    // SHARED MOCKS & SETUP
    // ==========================================================================
    
    private $db;
    private $repository;
    private $garageService;
    private $authService;
    private $logger;
    private $view;
    private $request;
    private $response;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->repository = $this->createMock(VehicleRepository::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->logger = $this->createMock(AuditLogger::class);
        $this->view = $this->createMock(Twig::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->response = new Response();
        
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ==========================================================================
    // 1. GarageBuySlotAction - GARÁZSHELYEK VÁSÁRLÁSA
    // ==========================================================================

    // --------------------------------------------------------------------------
    // 1.1 Autentikáció Tesztek
    // --------------------------------------------------------------------------

    public function testBuySlot_NotLoggedIn_RedirectsToLogin(): void
    {
        $action = new GarageBuySlotAction(
            $this->createMock(GarageService::class),
            $this->authService,
            $this->logger
        );

        $this->logger->expects($this->once())
            ->method('log')
            ->with('auth_failed', null, ['reason' => 'no_user_id_in_session', 'action' => 'GarageBuySlotAction']);

        $response = ($action)($this->request, $this->response);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    // --------------------------------------------------------------------------
    // 1.2 Input Validáció Tesztek
    // --------------------------------------------------------------------------

    public function testBuySlot_MissingSlotsParameter_RedirectsToExpand(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn([]);

        $action = new GarageBuySlotAction(
            $this->createMock(GarageService::class),
            $this->authService,
            $this->logger
        );

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_invalid_input', 1, ['error' => 'slots_parameter_missing']);

        $response = ($action)($this->request, $this->response);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    public function testBuySlot_SlotsValueIsZero_RedirectsToExpand(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => 0]);

        $action = new GarageBuySlotAction(
            $this->createMock(GarageService::class),
            $this->authService,
            $this->logger
        );

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_invalid_input', 1, ['error' => 'slots_value_is_0']);

        $response = ($action)($this->request, $this->response);
        
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    /**
     * @dataProvider invalidSlotsPackageProvider
     */
    public function testBuySlot_InvalidPackageValue_RedirectsToExpand(int $invalidSlots): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => $invalidSlots]);

        $action = new GarageBuySlotAction(
            $this->createMock(GarageService::class),
            $this->authService,
            $this->logger
        );

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_invalid_input', 1, ['error' => 'invalid_package_slots', 'value' => $invalidSlots]);

        $response = ($action)($this->request, $this->response);
        
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    public static function invalidSlotsPackageProvider(): array
    {
        return [
            'slots_3' => [3],
            'slots_15' => [15],
            'slots_999' => [999],
            'slots_1' => [1],
            'slots_200' => [200],
        ];
    }

    /**
     * @dataProvider nonNumericSlotsProvider
     */
    public function testBuySlot_SlotsNotNumeric_RedirectsToExpand(mixed $nonNumericValue): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => $nonNumericValue]);

        $action = new GarageBuySlotAction(
            $this->createMock(GarageService::class),
            $this->authService,
            $this->logger
        );

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_invalid_input', 1, ['error' => 'slots_value_is_not_numeric', 'value' => $nonNumericValue]);

        $response = ($action)($this->request, $this->response);
        
        $this->assertSame('/garage/expand', $response->getHeaderLine('Location'));
    }

    public static function nonNumericSlotsProvider(): array
    {
        return [
            'string_five' => ['five'],
            'string_abc' => ['abc'],
            'string_empty' => [''],
            'string_special' => ['@#$%'],
        ];
    }

    // --------------------------------------------------------------------------
    // 1.3 Valid Csomagok Tesztek
    // --------------------------------------------------------------------------

    /**
     * @dataProvider validPackagesProvider
     */
    public function testBuySlot_ValidPackage_SuccessfulPurchase(int $slots, int $expectedCost): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => $slots]);
        
        $this->authService->method('getAuthenticatedUser')
            ->willReturn(['id' => 1, 'country_code' => 'HU']);

        $garageService = $this->createMock(GarageService::class);
        $garageService->expects($this->once())
            ->method('buyGarageSlots')
            ->with(1, 'HU', $slots, $expectedCost);

        $action = new GarageBuySlotAction($garageService, $this->authService, $this->logger);

        $response = ($action)($this->request, $this->response);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/garage', $response->getHeaderLine('Location'));
    }

    public static function validPackagesProvider(): array
    {
        return [
            'package_5_slots' => [5, 700],
            'package_20_slots' => [20, 8000],
            'package_50_slots' => [50, 50000],
            'package_100_slots' => [100, 450000],
        ];
    }

    // --------------------------------------------------------------------------
    // 1.4 GarageService Exception Kezelés
    // --------------------------------------------------------------------------

    public function testBuySlot_ServiceException_LogsAndRedirects(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => 5]);
        
        $this->authService->method('getAuthenticatedUser')
            ->willReturn(['id' => 1, 'country_code' => 'HU']);

        $garageService = $this->createMock(GarageService::class);
        $garageService->method('buyGarageSlots')
            ->willThrowException(new Exception("Nincs elég pénzed!"));

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_action_error', 1, ['exception' => 'Nincs elég pénzed!']);

        $action = new GarageBuySlotAction($garageService, $this->authService, $this->logger);

        $response = ($action)($this->request, $this->response);
        
        $this->assertSame('/garage', $response->getHeaderLine('Location'));
    }

    public function testBuySlot_UserNotFound_HandlesGracefully(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => 5]);
        
        $this->authService->method('getAuthenticatedUser')
            ->willReturn(null);

        $garageService = $this->createMock(GarageService::class);
        
        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_action_error', 1, $this->anything());

        $action = new GarageBuySlotAction($garageService, $this->authService, $this->logger);

        $response = ($action)($this->request, $this->response);
        
        $this->assertSame('/garage', $response->getHeaderLine('Location'));
    }

    // ==========================================================================
    // 2. GarageExpandAction - BŐVÍTÉSI OLDAL
    // ==========================================================================

    // --------------------------------------------------------------------------
    // 2.1 Autentikáció Tesztek
    // --------------------------------------------------------------------------

    public function testExpand_NotLoggedIn_RedirectsToLogin(): void
    {
        $action = new GarageExpandAction($this->view, $this->authService, $this->logger);

        $this->logger->expects($this->once())
            ->method('log')
            ->with('auth_failed', null, ['reason' => 'no_user_id_in_session', 'action' => 'GarageExpandAction']);

        $response = ($action)($this->request, $this->response);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    // --------------------------------------------------------------------------
    // 2.2 View Rendering Tesztek
    // --------------------------------------------------------------------------

    public function testExpand_LoggedIn_RendersViewWithPackages(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->request->method('hasHeader')->with('HX-Request')->willReturn(false);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/expand.twig',
                $this->callback(function($data) {
                    return isset($data['packages']) 
                        && count($data['packages']) === 4
                        && $data['packages'][0]['slots'] === 5
                        && $data['packages'][0]['price'] === 700
                        && $data['packages'][1]['slots'] === 20
                        && $data['packages'][1]['price'] === 8000
                        && $data['packages'][2]['slots'] === 50
                        && $data['packages'][2]['price'] === 50000
                        && $data['packages'][3]['slots'] === 100
                        && $data['packages'][3]['price'] === 450000;
                })
            )
            ->willReturn($this->response);

        $action = new GarageExpandAction($this->view, $this->authService, $this->logger);
        ($action)($this->request, $this->response);
    }

    public function testExpand_LoggedIn_LogsViewAccess(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->request->method('hasHeader')->with('HX-Request')->willReturn(false);
        $this->view->method('render')->willReturn($this->response);

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_expand_view', 1, ['is_ajax' => false]);

        $action = new GarageExpandAction($this->view, $this->authService, $this->logger);
        ($action)($this->request, $this->response);
    }

    // --------------------------------------------------------------------------
    // 2.3 HTMX Request Kezelés
    // --------------------------------------------------------------------------

    public function testExpand_HtmxRequest_SetsIsAjaxTrue(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->request->method('hasHeader')->with('HX-Request')->willReturn(true);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/expand.twig',
                $this->callback(function($data) {
                    return $data['is_ajax'] === true;
                })
            )
            ->willReturn($this->response);

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_expand_view', 1, ['is_ajax' => true]);

        $action = new GarageExpandAction($this->view, $this->authService, $this->logger);
        ($action)($this->request, $this->response);
    }

    public function testExpand_RegularRequest_SetsIsAjaxFalse(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->request->method('hasHeader')->with('HX-Request')->willReturn(false);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/expand.twig',
                $this->callback(function($data) {
                    return $data['is_ajax'] === false;
                })
            )
            ->willReturn($this->response);

        $action = new GarageExpandAction($this->view, $this->authService, $this->logger);
        ($action)($this->request, $this->response);
    }

    // ==========================================================================
    // 3. GarageListAction - JÁRMŰVEK LISTÁZÁSA
    // ==========================================================================

    // --------------------------------------------------------------------------
    // 3.1 Autentikáció Tesztek
    // --------------------------------------------------------------------------

    public function testList_NotLoggedIn_RedirectsToLogin(): void
    {
        $action = new GarageListAction($this->view, $this->authService, $this->repository, $this->logger);

        $this->logger->expects($this->once())
            ->method('log')
            ->with('auth_failed', null, ['reason' => 'no_user_id_in_session', 'action' => 'GarageListAction']);

        $response = ($action)($this->request, $this->response);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    // --------------------------------------------------------------------------
    // 3.2 Járművek Lekérdezése
    // --------------------------------------------------------------------------

    public function testList_NoVehicles_ReturnsEmptyList(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->repository->method('getUserVehicles')->willReturn([]);
        $this->repository->method('getGarageCapacity')->willReturn(10);
        $this->request->method('hasHeader')->willReturn(false);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/index.twig',
                $this->callback(function($data) {
                    return $data['total_vehicles'] === 0
                        && $data['vehicles'] === [];
                })
            )
            ->willReturn($this->response);

        $action = new GarageListAction($this->view, $this->authService, $this->repository, $this->logger);
        ($action)($this->request, $this->response);
    }

    public function testList_HasVehicles_ReturnsCorrectCount(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        $vehicles = [
            ['id' => 1, 'name' => 'BMW', 'country' => 'HU'],
            ['id' => 2, 'name' => 'Audi', 'country' => 'HU'],
            ['id' => 3, 'name' => 'Mercedes', 'country' => 'US'],
        ];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->repository->method('getUserVehicles')->willReturn($vehicles);
        $this->repository->method('getGarageCapacity')->willReturn(10);
        $this->request->method('hasHeader')->willReturn(false);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/index.twig',
                $this->callback(function($data) {
                    return $data['total_vehicles'] === 3;
                })
            )
            ->willReturn($this->response);

        $action = new GarageListAction($this->view, $this->authService, $this->repository, $this->logger);
        ($action)($this->request, $this->response);
    }

    // --------------------------------------------------------------------------
    // 3.3 Kapacitás Számítás
    // --------------------------------------------------------------------------

    public function testList_CapacityCalculation_FreeSlotsCorrect(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        $vehicles = [
            ['id' => 1, 'name' => 'BMW', 'country' => 'HU'],
            ['id' => 2, 'name' => 'Audi', 'country' => 'HU'],
        ];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->repository->method('getUserVehicles')->willReturn($vehicles);
        $this->repository->method('getGarageCapacity')->willReturn(5);
        $this->request->method('hasHeader')->willReturn(false);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/index.twig',
                $this->callback(function($data) {
                    return $data['capacity'] === 5
                        && $data['free_slots'] === 3; // 5 - 2 HU vehicles
                })
            )
            ->willReturn($this->response);

        $action = new GarageListAction($this->view, $this->authService, $this->repository, $this->logger);
        ($action)($this->request, $this->response);
    }

    public function testList_OverCapacity_FreeSlotsIsZero(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        $vehicles = [
            ['id' => 1, 'country' => 'HU'],
            ['id' => 2, 'country' => 'HU'],
            ['id' => 3, 'country' => 'HU'],
            ['id' => 4, 'country' => 'HU'],
            ['id' => 5, 'country' => 'HU'],
            ['id' => 6, 'country' => 'HU'],
            ['id' => 7, 'country' => 'HU'],
        ];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->repository->method('getUserVehicles')->willReturn($vehicles);
        $this->repository->method('getGarageCapacity')->willReturn(5);
        $this->request->method('hasHeader')->willReturn(false);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/index.twig',
                $this->callback(function($data) {
                    return $data['free_slots'] === 0; // max(0, 5-7) = 0
                })
            )
            ->willReturn($this->response);

        $action = new GarageListAction($this->view, $this->authService, $this->repository, $this->logger);
        ($action)($this->request, $this->response);
    }

    public function testList_ZeroCapacity_ZeroFreeSlots(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->repository->method('getUserVehicles')->willReturn([]);
        $this->repository->method('getGarageCapacity')->willReturn(0);
        $this->request->method('hasHeader')->willReturn(false);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/index.twig',
                $this->callback(function($data) {
                    return $data['capacity'] === 0
                        && $data['free_slots'] === 0;
                })
            )
            ->willReturn($this->response);

        $action = new GarageListAction($this->view, $this->authService, $this->repository, $this->logger);
        ($action)($this->request, $this->response);
    }

    // --------------------------------------------------------------------------
    // 3.4 Ország Specifikus Számítás
    // --------------------------------------------------------------------------

    public function testList_MultipleCountries_OnlyCurrentCountryCountedForFreeSlots(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        $vehicles = [
            ['id' => 1, 'country' => 'HU'],
            ['id' => 2, 'country' => 'HU'],
            ['id' => 3, 'country' => 'HU'],
            ['id' => 4, 'country' => 'US'],
            ['id' => 5, 'country' => 'US'],
        ];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->repository->method('getUserVehicles')->willReturn($vehicles);
        $this->repository->method('getGarageCapacity')->with(1, 'HU')->willReturn(10);
        $this->request->method('hasHeader')->willReturn(false);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/index.twig',
                $this->callback(function($data) {
                    // Total is 5, but only 3 are in HU, capacity is 10
                    // free_slots = 10 - 3 = 7
                    return $data['total_vehicles'] === 5
                        && $data['free_slots'] === 7
                        && $data['current_country'] === 'HU';
                })
            )
            ->willReturn($this->response);

        $action = new GarageListAction($this->view, $this->authService, $this->repository, $this->logger);
        ($action)($this->request, $this->response);
    }

    // --------------------------------------------------------------------------
    // 3.5 Repository Hiba
    // --------------------------------------------------------------------------

    public function testList_RepositoryException_Returns500(): void
    {
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->repository->method('getUserVehicles')
            ->willThrowException(new Exception("Database connection failed"));

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_list_error', 1, ['error' => 'Database connection failed']);

        $action = new GarageListAction($this->view, $this->authService, $this->repository, $this->logger);
        $response = ($action)($this->request, $this->response);
        
        $this->assertSame(500, $response->getStatusCode());
    }

    // ==========================================================================
    // 4. VehicleDetailsAction - JÁRMŰ RÉSZLETEK
    // ==========================================================================

    // --------------------------------------------------------------------------
    // 4.1 Autentikáció Tesztek
    // --------------------------------------------------------------------------

    public function testVehicleDetails_NotLoggedIn_Returns401(): void
    {
        $action = new VehicleDetailsAction($this->view, $this->repository);

        $response = ($action)($this->request, $this->response, ['id' => 1]);
        
        $this->assertSame(401, $response->getStatusCode());
    }

    // --------------------------------------------------------------------------
    // 4.2 Jármű Lekérdezés
    // --------------------------------------------------------------------------

    public function testVehicleDetails_ExistingVehicle_RendersView(): void
    {
        $_SESSION['user_id'] = 1;
        $vehicle = ['id' => 1, 'name' => 'BMW M5', 'user_id' => 1];

        $this->repository->method('getVehicleDetails')->with(1)->willReturn($vehicle);

        $this->view->expects($this->once())
            ->method('render')
            ->with(
                $this->anything(),
                'garage/_vehicle_details_modal.twig',
                ['vehicle' => $vehicle]
            )
            ->willReturn($this->response);

        $action = new VehicleDetailsAction($this->view, $this->repository);
        ($action)($this->request, $this->response, ['id' => 1]);
    }

    public function testVehicleDetails_NonExistingVehicle_Returns404(): void
    {
        $_SESSION['user_id'] = 1;

        $this->repository->method('getVehicleDetails')->with(99999)->willReturn(null);

        $action = new VehicleDetailsAction($this->view, $this->repository);
        $response = ($action)($this->request, $this->response, ['id' => 99999]);
        
        $this->assertSame(404, $response->getStatusCode());
    }

    // --------------------------------------------------------------------------
    // 4.3 Tulajdonjog Ellenőrzés
    // --------------------------------------------------------------------------

    public function testVehicleDetails_NotOwned_Returns403(): void
    {
        $_SESSION['user_id'] = 1;
        $vehicle = ['id' => 5, 'name' => 'Someone Else BMW', 'user_id' => 999];

        $this->repository->method('getVehicleDetails')->with(5)->willReturn($vehicle);

        $this->view->expects($this->never())->method('render');

        $action = new VehicleDetailsAction($this->view, $this->repository);
        $response = ($action)($this->request, $this->response, ['id' => 5]);
        
        $this->assertSame(403, $response->getStatusCode());
    }

    // ==========================================================================
    // 5. GarageService - ÜZLETI LOGIKA
    // ==========================================================================

    // --------------------------------------------------------------------------
    // 5.1 Slot Vásárlás - Sikeres Tranzakció
    // --------------------------------------------------------------------------

    public function testService_BuySlots_SufficientFunds_Success(): void
    {
        $userId = 1;
        $country = 'HU';
        $slots = 5;
        $cost = 700;
        $userMoney = 10000;

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->method('fetchOne')->willReturn($userMoney);
        
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->with(
                "UPDATE users SET money = money - :cost WHERE id = :id",
                ['cost' => $cost, 'id' => $userId]
            );

        $this->repository->expects($this->once())
            ->method('addGarageSlots')
            ->with($userId, $country, $slots);

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_success', $userId, [
                'slots' => $slots,
                'cost' => $cost,
                'country' => $country
            ]);

        $this->db->expects($this->once())->method('commit');

        $service = new GarageService($this->db, $this->repository, $this->logger);
        $service->buyGarageSlots($userId, $country, $slots, $cost);
    }

    // --------------------------------------------------------------------------
    // 5.2 Slot Vásárlás - Nincs Elég Pénz
    // --------------------------------------------------------------------------

    public function testService_BuySlots_InsufficientFunds_ThrowsException(): void
    {
        $userId = 1;
        $cost = 700;
        $userMoney = 500;

        $this->db->method('beginTransaction');
        $this->db->method('fetchOne')->willReturn($userMoney);
        $this->db->expects($this->once())->method('rollBack');

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_failed', $userId, [
                'reason' => 'insufficient_funds',
                'needed' => $cost,
                'available' => $userMoney
            ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Nincs elég pénzed!");

        $service = new GarageService($this->db, $this->repository, $this->logger);
        $service->buyGarageSlots($userId, 'HU', 5, $cost);
    }

    // --------------------------------------------------------------------------
    // 5.3 Transaction Rollback
    // --------------------------------------------------------------------------

    public function testService_BuySlots_RepositoryException_RollsBack(): void
    {
        $userId = 1;
        $userMoney = 10000;

        $this->db->method('beginTransaction');
        $this->db->method('fetchOne')->willReturn($userMoney);
        $this->db->method('executeStatement');
        
        $this->repository->method('addGarageSlots')
            ->willThrowException(new Exception("DB connection failed"));

        $this->db->expects($this->once())->method('rollBack');

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_error', $userId, ['error' => 'DB connection failed']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("DB connection failed");

        $service = new GarageService($this->db, $this->repository, $this->logger);
        $service->buyGarageSlots($userId, 'HU', 5, 700);
    }

    // --------------------------------------------------------------------------
    // 5.4 Input Validáció a Service-ben
    // --------------------------------------------------------------------------

    public function testService_BuySlots_InvalidUserId_ThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid userId: 0");

        $service = new GarageService($this->db, $this->repository, $this->logger);
        $service->buyGarageSlots(0, 'HU', 5, 700);
    }

    public function testService_BuySlots_NegativeUserId_ThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid userId: -1");

        $service = new GarageService($this->db, $this->repository, $this->logger);
        $service->buyGarageSlots(-1, 'HU', 5, 700);
    }

    public function testService_BuySlots_EmptyCountry_ThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid countryCode");

        $service = new GarageService($this->db, $this->repository, $this->logger);
        $service->buyGarageSlots(1, '', 5, 700);
    }

    public function testService_BuySlots_InvalidSlots_ThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid slots: 0");

        $service = new GarageService($this->db, $this->repository, $this->logger);
        $service->buyGarageSlots(1, 'HU', 0, 700);
    }

    public function testService_BuySlots_NegativeSlots_ThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid slots: -5");

        $service = new GarageService($this->db, $this->repository, $this->logger);
        $service->buyGarageSlots(1, 'HU', -5, 700);
    }

    public function testService_BuySlots_NegativeCost_ThrowsException(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid cost: -100");

        $service = new GarageService($this->db, $this->repository, $this->logger);
        $service->buyGarageSlots(1, 'HU', 5, -100);
    }

    public function testService_BuySlots_UserNotFound_ThrowsException(): void
    {
        $this->db->method('beginTransaction');
        $this->db->method('fetchOne')->willReturn(false);
        $this->db->expects($this->once())->method('rollBack');

        $this->logger->expects($this->once())
            ->method('log')
            ->with('garage_buy_error', 1, ['error' => 'User not found for money check']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("User not found for money check");

        $service = new GarageService($this->db, $this->repository, $this->logger);
        $service->buyGarageSlots(1, 'HU', 5, 700);
    }

    // ==========================================================================
    // 6. VehicleRepository - ADATBÁZIS MŰVELETEK (Unit with mocked DB)
    // ==========================================================================

    // Note: These tests use a real VehicleRepository with mocked Connection
    // For true integration tests, use a test database

    public function testRepository_GetGarageCapacity_NoPropertyNoSlots_ReturnsZero(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')
            ->willReturnOnConsecutiveCalls(0, 0); // property capacity = 0, purchased slots = 0

        $repo = new VehicleRepository($db);
        $capacity = $repo->getGarageCapacity(1, 'HU');
        
        $this->assertSame(0, $capacity);
    }

    public function testRepository_GetGarageCapacity_PropertyOnly_ReturnsPropertyCapacity(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')
            ->willReturnOnConsecutiveCalls(10, 0); // property capacity = 10, purchased slots = 0

        $repo = new VehicleRepository($db);
        $capacity = $repo->getGarageCapacity(1, 'HU');
        
        $this->assertSame(10, $capacity);
    }

    public function testRepository_GetGarageCapacity_SlotsOnly_ReturnsPurchasedSlots(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')
            ->willReturnOnConsecutiveCalls(0, 20); // property capacity = 0, purchased slots = 20

        $repo = new VehicleRepository($db);
        $capacity = $repo->getGarageCapacity(1, 'HU');
        
        $this->assertSame(20, $capacity);
    }

    public function testRepository_GetGarageCapacity_PropertyAndSlots_ReturnsSum(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')
            ->willReturnOnConsecutiveCalls(10, 20); // property capacity = 10, purchased slots = 20

        $repo = new VehicleRepository($db);
        $capacity = $repo->getGarageCapacity(1, 'HU');
        
        $this->assertSame(30, $capacity);
    }

    // ==========================================================================
    // 7. EDGE CASES
    // ==========================================================================

    public function testBuySlot_StringUserId_CastsToInt(): void
    {
        $_SESSION['user_id'] = '123'; // String instead of int
        $this->request->method('getParsedBody')->willReturn(['slots' => 5]);
        
        $this->authService->method('getAuthenticatedUser')
            ->with(123) // Should be cast to int
            ->willReturn(['id' => 123, 'country_code' => 'HU']);

        $garageService = $this->createMock(GarageService::class);
        $garageService->expects($this->once())
            ->method('buyGarageSlots')
            ->with(123, 'HU', 5, 700);

        $action = new GarageBuySlotAction($garageService, $this->authService, $this->logger);
        ($action)($this->request, $this->response);
    }

    public function testBuySlot_StringSlots_CastsToInt(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => '20']); // String "20"
        
        $this->authService->method('getAuthenticatedUser')
            ->willReturn(['id' => 1, 'country_code' => 'HU']);

        $garageService = $this->createMock(GarageService::class);
        $garageService->expects($this->once())
            ->method('buyGarageSlots')
            ->with(1, 'HU', 20, 8000); // Should be cast to int 20

        $action = new GarageBuySlotAction($garageService, $this->authService, $this->logger);
        ($action)($this->request, $this->response);
    }

    public function testService_ZeroCostAllowed(): void
    {
        $this->db->method('beginTransaction');
        $this->db->method('fetchOne')->willReturn(1000);
        $this->db->method('executeStatement');
        $this->db->method('commit');

        $service = new GarageService($this->db, $this->repository, $this->logger);
        
        // Zero cost should be allowed (free slots)
        $service->buyGarageSlots(1, 'HU', 5, 0);
        
        $this->assertTrue(true); // No exception thrown
    }

    public function testVehicleDetails_ZeroId_HandlesProperly(): void
    {
        $_SESSION['user_id'] = 1;

        $this->repository->method('getVehicleDetails')->with(0)->willReturn(null);

        $action = new VehicleDetailsAction($this->view, $this->repository);
        $response = ($action)($this->request, $this->response, ['id' => 0]);
        
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testVehicleDetails_NegativeId_HandlesProperly(): void
    {
        $_SESSION['user_id'] = 1;

        $this->repository->method('getVehicleDetails')->with(-1)->willReturn(null);

        $action = new VehicleDetailsAction($this->view, $this->repository);
        $response = ($action)($this->request, $this->response, ['id' => -1]);
        
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * @dataProvider specialCountryCodeProvider
     */
    public function testService_SpecialCountryCodes_HandledProperly(string $countryCode): void
    {
        $this->db->method('beginTransaction');
        $this->db->method('fetchOne')->willReturn(10000);
        $this->db->method('executeStatement');
        $this->db->method('commit');

        $this->repository->expects($this->once())
            ->method('addGarageSlots')
            ->with(1, $countryCode, 5);

        $service = new GarageService($this->db, $this->repository, $this->logger);
        $service->buyGarageSlots(1, $countryCode, 5, 700);
    }

    public static function specialCountryCodeProvider(): array
    {
        return [
            'hungary' => ['HU'],
            'usa' => ['US'],
            'japan' => ['JP'],
            'germany' => ['DE'],
            'lowercase' => ['hu'],
            'mixed_case' => ['Hu'],
        ];
    }

    // ==========================================================================
    // 8. INTEGRÁCIÓS TESZTEK (Mocked)
    // ==========================================================================

    public function testIntegration_CompletePurchaseFlow(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => 5]);
        
        $user = ['id' => 1, 'country_code' => 'HU'];
        $this->authService->method('getAuthenticatedUser')->willReturn($user);

        // Create real service with mocked dependencies
        $this->db->method('beginTransaction');
        $this->db->method('fetchOne')->willReturn(10000);
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->with(
                "UPDATE users SET money = money - :cost WHERE id = :id",
                ['cost' => 700, 'id' => 1]
            );
        $this->db->method('commit');

        $this->repository->expects($this->once())
            ->method('addGarageSlots')
            ->with(1, 'HU', 5);

        $realService = new GarageService($this->db, $this->repository, $this->logger);
        $action = new GarageBuySlotAction($realService, $this->authService, $this->logger);

        $response = ($action)($this->request, $this->response);
        
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/garage', $response->getHeaderLine('Location'));
    }

    public function testIntegration_FailedPurchase_NoChanges(): void
    {
        $_SESSION['user_id'] = 1;
        $this->request->method('getParsedBody')->willReturn(['slots' => 5]);
        
        $user = ['id' => 1, 'country_code' => 'HU'];
        $this->authService->method('getAuthenticatedUser')->willReturn($user);

        // User has insufficient funds
        $this->db->method('beginTransaction');
        $this->db->method('fetchOne')->willReturn(100); // Only 100, needs 700
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        // Repository should never be called for slot addition
        $this->repository->expects($this->never())->method('addGarageSlots');

        $realService = new GarageService($this->db, $this->repository, $this->logger);
        $action = new GarageBuySlotAction($realService, $this->authService, $this->logger);

        $response = ($action)($this->request, $this->response);
        
        // Should still redirect but with no changes made
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/garage', $response->getHeaderLine('Location'));
    }

    public function testIntegration_ViewExpand_ThenBuySlots(): void
    {
        // Step 1: View expand page
        $_SESSION['user_id'] = 1;
        $user = ['id' => 1, 'country_code' => 'HU'];
        
        $this->authService->method('getAuthenticatedUser')->willReturn($user);
        $this->request->method('hasHeader')->with('HX-Request')->willReturn(false);
        $this->view->method('render')->willReturn($this->response);

        $expandAction = new GarageExpandAction($this->view, $this->authService, $this->logger);
        $response1 = ($expandAction)($this->request, $this->response);
        
        // View should be rendered (200 status from Twig)
        $this->assertNotNull($response1);

        // Step 2: Buy slots
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->request->method('getParsedBody')->willReturn(['slots' => 100]);
        
        $this->db->method('beginTransaction');
        $this->db->method('fetchOne')->willReturn(1000000);
        $this->db->method('executeStatement');
        $this->db->method('commit');

        $realService = new GarageService($this->db, $this->repository, $this->logger);
        $buyAction = new GarageBuySlotAction($realService, $this->authService, $this->logger);

        $response2 = ($buyAction)($this->request, new Response());
        
        $this->assertSame(302, $response2->getStatusCode());
        $this->assertSame('/garage', $response2->getHeaderLine('Location'));
    }
}
