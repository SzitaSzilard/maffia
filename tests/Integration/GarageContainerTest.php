<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use DI\Container;
use Doctrine\DBAL\Connection;
use Slim\Views\Twig;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Modules\Garage\Domain\VehicleRepository;
use Netmafia\Modules\Garage\Actions\GarageListAction;
use Netmafia\Modules\Garage\Actions\GarageExpandAction;
use Netmafia\Modules\Garage\Actions\GarageBuySlotAction;
use Netmafia\Modules\Garage\Actions\VehicleDetailsAction;

/**
 * Container Integration Tests
 * 
 * These tests verify that ALL classes are properly configured in the DI container.
 * This catches configuration errors like:
 * - Missing dependencies
 * - Wrong number of constructor arguments
 * - Incorrect class names
 * - Circular dependencies
 */
class GarageContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $containerDefinitions = require __DIR__ . '/../../config/container.php';
        $this->container = new Container($containerDefinitions);
    }

    // ==========================================================================
    // DOMAIN SERVICES CONTAINER RESOLUTION
    // ==========================================================================

    public function testContainerResolvesVehicleRepository(): void
    {
        $repository = $this->container->get(VehicleRepository::class);
        
        $this->assertInstanceOf(VehicleRepository::class, $repository);
    }

    public function testContainerResolvesGarageService(): void
    {
        $service = $this->container->get(GarageService::class);
        
        $this->assertInstanceOf(GarageService::class, $service);
    }

    public function testGarageServiceHasCorrectDependencies(): void
    {
        $service = $this->container->get(GarageService::class);
        
        // Use reflection to verify dependencies
        $reflection = new \ReflectionClass($service);
        
        $dbProperty = $reflection->getProperty('db');
        $dbProperty->setAccessible(true);
        $this->assertInstanceOf(Connection::class, $dbProperty->getValue($service));
        
        $repoProperty = $reflection->getProperty('repository');
        $repoProperty->setAccessible(true);
        $this->assertInstanceOf(VehicleRepository::class, $repoProperty->getValue($service));
        
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $this->assertInstanceOf(AuditLogger::class, $loggerProperty->getValue($service));
    }

    // ==========================================================================
    // ACTION CLASSES CONTAINER RESOLUTION
    // ==========================================================================

    public function testContainerResolvesGarageListAction(): void
    {
        $action = $this->container->get(GarageListAction::class);
        
        $this->assertInstanceOf(GarageListAction::class, $action);
    }

    public function testGarageListActionHasCorrectDependencies(): void
    {
        $action = $this->container->get(GarageListAction::class);
        
        $reflection = new \ReflectionClass($action);
        
        $viewProperty = $reflection->getProperty('view');
        $viewProperty->setAccessible(true);
        $this->assertInstanceOf(Twig::class, $viewProperty->getValue($action));
        
        $authProperty = $reflection->getProperty('authService');
        $authProperty->setAccessible(true);
        $this->assertInstanceOf(AuthService::class, $authProperty->getValue($action));
        
        $repoProperty = $reflection->getProperty('vehicleRepository');
        $repoProperty->setAccessible(true);
        $this->assertInstanceOf(VehicleRepository::class, $repoProperty->getValue($action));
        
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $this->assertInstanceOf(AuditLogger::class, $loggerProperty->getValue($action));
    }

    public function testContainerResolvesGarageExpandAction(): void
    {
        $action = $this->container->get(GarageExpandAction::class);
        
        $this->assertInstanceOf(GarageExpandAction::class, $action);
    }

    public function testGarageExpandActionHasCorrectDependencies(): void
    {
        $action = $this->container->get(GarageExpandAction::class);
        
        $reflection = new \ReflectionClass($action);
        
        $viewProperty = $reflection->getProperty('view');
        $viewProperty->setAccessible(true);
        $this->assertInstanceOf(Twig::class, $viewProperty->getValue($action));
        
        $authProperty = $reflection->getProperty('authService');
        $authProperty->setAccessible(true);
        $this->assertInstanceOf(AuthService::class, $authProperty->getValue($action));
        
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $this->assertInstanceOf(AuditLogger::class, $loggerProperty->getValue($action));
    }

    public function testContainerResolvesGarageBuySlotAction(): void
    {
        $action = $this->container->get(GarageBuySlotAction::class);
        
        $this->assertInstanceOf(GarageBuySlotAction::class, $action);
    }

    public function testGarageBuySlotActionHasCorrectDependencies(): void
    {
        $action = $this->container->get(GarageBuySlotAction::class);
        
        $reflection = new \ReflectionClass($action);
        
        $serviceProperty = $reflection->getProperty('garageService');
        $serviceProperty->setAccessible(true);
        $this->assertInstanceOf(GarageService::class, $serviceProperty->getValue($action));
        
        $authProperty = $reflection->getProperty('authService');
        $authProperty->setAccessible(true);
        $this->assertInstanceOf(AuthService::class, $authProperty->getValue($action));
        
        $loggerProperty = $reflection->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $this->assertInstanceOf(AuditLogger::class, $loggerProperty->getValue($action));
    }

    public function testContainerResolvesVehicleDetailsAction(): void
    {
        $action = $this->container->get(VehicleDetailsAction::class);
        
        $this->assertInstanceOf(VehicleDetailsAction::class, $action);
    }

    public function testVehicleDetailsActionHasCorrectDependencies(): void
    {
        $action = $this->container->get(VehicleDetailsAction::class);
        
        $reflection = new \ReflectionClass($action);
        
        $viewProperty = $reflection->getProperty('view');
        $viewProperty->setAccessible(true);
        $this->assertInstanceOf(Twig::class, $viewProperty->getValue($action));
        
        $repoProperty = $reflection->getProperty('repository');
        $repoProperty->setAccessible(true);
        $this->assertInstanceOf(VehicleRepository::class, $repoProperty->getValue($action));
    }

    // ==========================================================================
    // SHARED DEPENDENCIES
    // ==========================================================================

    public function testContainerResolvesConnection(): void
    {
        $connection = $this->container->get(Connection::class);
        
        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function testContainerResolvesTwig(): void
    {
        $twig = $this->container->get(Twig::class);
        
        $this->assertInstanceOf(Twig::class, $twig);
    }

    public function testContainerResolvesAuditLogger(): void
    {
        $logger = $this->container->get(AuditLogger::class);
        
        $this->assertInstanceOf(AuditLogger::class, $logger);
    }

    public function testContainerResolvesAuthService(): void
    {
        $authService = $this->container->get(AuthService::class);
        
        $this->assertInstanceOf(AuthService::class, $authService);
    }

    // ==========================================================================
    // SINGLETON VERIFICATION
    // ==========================================================================

    public function testConnectionIsSingleton(): void
    {
        $connection1 = $this->container->get(Connection::class);
        $connection2 = $this->container->get(Connection::class);
        
        $this->assertSame($connection1, $connection2, 'Connection should be singleton');
    }

    public function testAuditLoggerIsSingleton(): void
    {
        $logger1 = $this->container->get(AuditLogger::class);
        $logger2 = $this->container->get(AuditLogger::class);
        
        $this->assertSame($logger1, $logger2, 'AuditLogger should be singleton');
    }

    // ==========================================================================
    // CONSTRUCTOR ARGUMENT COUNT VALIDATION
    // ==========================================================================

    /**
     * @dataProvider garageClassProvider
     */
    public function testGarageClassConstructorArgumentsMatchContainer(string $className): void
    {
        // This test will fail if container passes wrong number of arguments
        try {
            $instance = $this->container->get($className);
            $this->assertInstanceOf($className, $instance);
        } catch (\Throwable $e) {
            $this->fail("Failed to resolve $className: " . $e->getMessage());
        }
    }

    public static function garageClassProvider(): array
    {
        return [
            'VehicleRepository' => [VehicleRepository::class],
            'GarageService' => [GarageService::class],
            'GarageListAction' => [GarageListAction::class],
            'GarageExpandAction' => [GarageExpandAction::class],
            'GarageBuySlotAction' => [GarageBuySlotAction::class],
            'VehicleDetailsAction' => [VehicleDetailsAction::class],
        ];
    }
}
