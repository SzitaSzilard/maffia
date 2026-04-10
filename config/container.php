<?php
declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Dotenv\Dotenv;
use Netmafia\Infrastructure\SessionService;
use Netmafia\Infrastructure\CacheService;
use Netmafia\Infrastructure\RateLimiter;
use Netmafia\Infrastructure\AuditLogger;
use Netmafia\Modules\Health\Domain\HealthService;
use Netmafia\Modules\Notifications\Domain\NotificationService;

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

return [
    // Settings
    'settings' => function () {
        return [
            'displayErrorDetails' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'db' => [
                'dbname' => $_ENV['DB_NAME'],
                'user' => $_ENV['DB_USER'],
                'password' => $_ENV['DB_PASS'],
                'host' => $_ENV['DB_HOST'],
                'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
                'charset' => 'utf8mb4',
            ],
            'cache' => [
                'driver' => $_ENV['CACHE_DRIVER'] ?? 'array',
                'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
                'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
                'prefix' => $_ENV['CACHE_PREFIX'] ?? 'netmafia:',
            ],
        ];
    },

    // Session Service (singleton)
    SessionService::class => function () {
        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return new SessionService($isSecure);
    },

    // Cache Service (singleton)
    CacheService::class => function (ContainerInterface $c) {
        $settings = $c->get('settings');
        $cacheConfig = $settings['cache'];
        
        return new CacheService(
            $cacheConfig['driver'],
            [
                'host' => $cacheConfig['host'],
                'port' => $cacheConfig['port'],
                'prefix' => $cacheConfig['prefix'],
            ]
        );
    },

    // Rate Limiter (uses CacheService)
    RateLimiter::class => function (ContainerInterface $c) {
        return new RateLimiter($c->get(CacheService::class));
    },

    // Audit Logger (uses Database)
    AuditLogger::class => function (ContainerInterface $c) {
        return new AuditLogger($c->get(Connection::class));
    },

    // Twig Template Engine
    Twig::class => function (ContainerInterface $c) {
        $twig = Twig::create(__DIR__ . '/../templates', [
            'cache' => false, // Set to path in production
            'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]);
        return $twig;
    },

    // Database Connection
    Connection::class => function (ContainerInterface $c) {
        $settings = $c->get('settings');
        return DriverManager::getConnection($settings['db']);
    },

    // Notification Service
    NotificationService::class => function (ContainerInterface $c) {
        return new NotificationService(
            $c->get(Connection::class),
            $c->get(CacheService::class)
        );
    },

    // Health Service (with NotificationService for death notifications)
    HealthService::class => function (ContainerInterface $c) {
        return new HealthService(
            $c->get(Connection::class),
            $c->get(NotificationService::class)
        );
    },

    // Auth Service
    \Netmafia\Modules\Auth\Domain\AuthService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Auth\Domain\AuthService($c->get(Connection::class));
    },

    // Ban Service
    \Netmafia\Modules\Auth\Domain\BanService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Auth\Domain\BanService($c->get(Connection::class));
    },

    // Register Action (with rate limiting)
    \Netmafia\Modules\Auth\Actions\RegisterAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Auth\Actions\RegisterAction(
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(RateLimiter::class),
            $c->get(SessionService::class),
            $c->get(Twig::class)
        );
    },

    // Message Service
    \Netmafia\Modules\Messages\Domain\MessageService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Messages\Domain\MessageService(
            $c->get(Connection::class),
            $c->get(CacheService::class)
        );
    },

    // Kocsma Service
    \Netmafia\Modules\Kocsma\Domain\KocsmaService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Kocsma\Domain\KocsmaService(
            $c->get(Connection::class),
            $c->get(AuditLogger::class)
        );
    },

    // Credit Service
    \Netmafia\Modules\Credits\Domain\CreditService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Credits\Domain\CreditService(
            $c->get(Connection::class),
            $c->get(AuditLogger::class)
        );
    },

    // Money Service
    \Netmafia\Modules\Money\Domain\MoneyService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Money\Domain\MoneyService(
            $c->get(Connection::class),
            $c->get(AuditLogger::class)
        );
    },

    // Bullet Service — töltény főkönyv
    \Netmafia\Modules\AmmoFactory\Domain\BulletService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\AmmoFactory\Domain\BulletService(
            $c->get(Connection::class),
            $c->get(AuditLogger::class)
        );
    },

    // Ledger Verifier — kereszt-currency integritás ellenőrző
    \Netmafia\Shared\Domain\Services\LedgerVerifier::class => function (ContainerInterface $c) {
        return new \Netmafia\Shared\Domain\Services\LedgerVerifier(
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(\Netmafia\Modules\Credits\Domain\CreditService::class),
            $c->get(\Netmafia\Modules\AmmoFactory\Domain\BulletService::class),
            $c->get(Connection::class),
            $c->get(AuditLogger::class)
        );
    },

    // XP Service (with NotificationService for rank up notifications)
    \Netmafia\Modules\Xp\Domain\XpService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Xp\Domain\XpService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Item\Domain\BuffService::class),
            $c->get(NotificationService::class)
        );
    },

    // Building Service (with MoneyService for audited transactions)
    \Netmafia\Modules\Buildings\Domain\BuildingService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Buildings\Domain\BuildingService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class)
        );
    },

    // Bank Service
    \Netmafia\Modules\Buildings\Domain\HospitalService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Buildings\Domain\HospitalService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(\Netmafia\Modules\Buildings\Domain\BuildingService::class)
        );
    },
    \Netmafia\Modules\Bank\Domain\BankService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Bank\Domain\BankService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(\Netmafia\Modules\Notifications\Domain\NotificationService::class),
            $c->get(AuditLogger::class)
        );
    },

    // Restaurant Service
    \Netmafia\Modules\Buildings\Domain\RestaurantService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Buildings\Domain\RestaurantService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(\Netmafia\Modules\Buildings\Domain\BuildingService::class)
        );
    },

    // Online Module
    \Netmafia\Modules\Online\Domain\OnlineService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Online\Domain\OnlineService(
            $c->get(Connection::class),
            $c->get(CacheService::class)
        );
    },

    \Netmafia\Modules\Online\Actions\OnlineAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Online\Actions\OnlineAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Online\Domain\OnlineService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class)
        );
    },

    // News Module
    \Netmafia\Modules\Game\Domain\NewsService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Game\Domain\NewsService($c->get(Connection::class));
    },

    \Netmafia\Modules\Game\Actions\NewsFormAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Game\Actions\NewsFormAction($c->get(Twig::class));
    },

    \Netmafia\Modules\Game\Actions\NewsCreateAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Game\Actions\NewsCreateAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Game\Domain\NewsService::class)
        );
    },

    \Netmafia\Modules\Game\Actions\NewsEditFormAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Game\Actions\NewsEditFormAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Game\Domain\NewsService::class)
        );
    },

    \Netmafia\Modules\Game\Actions\NewsUpdateAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Game\Actions\NewsUpdateAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Game\Domain\NewsService::class)
        );
    },

    \Netmafia\Modules\Game\Actions\NewsDeleteAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Game\Actions\NewsDeleteAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Game\Domain\NewsService::class)
        );
    },

    \Netmafia\Modules\Game\Actions\NewsDeleteConfirmAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Game\Actions\NewsDeleteConfirmAction();
    },

    \Netmafia\Modules\Game\Actions\NewsDeleteCancelAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Game\Actions\NewsDeleteCancelAction();
    },

    // Combat Module
    \Netmafia\Modules\Combat\Domain\CombatRepository::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Combat\Domain\CombatRepository(
            $c->get(Connection::class)
        );
    },

    // Combat Narrator
    \Netmafia\Modules\Combat\Domain\CombatNarrator::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Combat\Domain\CombatNarrator();
    },

    \Netmafia\Modules\Postal\Domain\PostalService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Postal\Domain\PostalService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(\Netmafia\Modules\Credits\Domain\CreditService::class),
            $c->get(\Netmafia\Modules\Item\Domain\InventoryService::class),
            $c->get(\Netmafia\Modules\Buildings\Domain\BuildingService::class),
            $c->get(CacheService::class),
            $c->get(\Netmafia\Modules\AmmoFactory\Domain\BulletService::class)
        );
    },

    \Netmafia\Modules\Combat\Domain\CombatService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Combat\Domain\CombatService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Combat\Domain\CombatRepository::class),
            $c->get(\Netmafia\Modules\Item\Domain\InventoryService::class),
            $c->get(\Netmafia\Modules\Item\Domain\ItemService::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(HealthService::class),
            $c->get(\Netmafia\Modules\Xp\Domain\XpService::class),
            $c->get(\Netmafia\Modules\Combat\Domain\CombatNarrator::class),
            $c->get(\Netmafia\Modules\Item\Domain\BuffService::class),
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class),
            $c->get(\Netmafia\Modules\AmmoFactory\Domain\BulletService::class)
        );
    },

    // Profile Module
    \Netmafia\Modules\Profile\Domain\ProfileService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Profile\Domain\ProfileService($c->get(Connection::class));
    },

    \Netmafia\Modules\Profile\Actions\ProfileAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Profile\Actions\ProfileAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Profile\Domain\ProfileService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class)
        );
    },

    // Dispatcher
    \Netmafia\Web\Actions\RootDispatcherAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Web\Actions\RootDispatcherAction(
            $c->get(\Netmafia\Modules\Profile\Actions\ProfileAction::class),
            $c->get(\Netmafia\Shared\Actions\UnderConstructionAction::class),
            $c->get(\Netmafia\Modules\Profile\Domain\ProfileService::class)
        );
    },

    // Search Module
    \Netmafia\Modules\Search\Domain\SearchService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Search\Domain\SearchService($c->get(Connection::class));
    },

    \Netmafia\Modules\Search\Actions\SearchAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Search\Actions\SearchAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Search\Domain\SearchService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class)
        );
    },

    // Ammo Factory Module
    \Netmafia\Modules\AmmoFactory\Domain\AmmoFactoryService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\AmmoFactory\Domain\AmmoFactoryService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(\Netmafia\Modules\AmmoFactory\Domain\BulletService::class)
        );
    },

    \Netmafia\Modules\AmmoFactory\Actions\AmmoFactoryAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\AmmoFactory\Actions\AmmoFactoryAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\AmmoFactory\Domain\AmmoFactoryService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(\Netmafia\Modules\Buildings\Domain\BuildingService::class),
            $c->get(Connection::class)
        );
    },

    \Netmafia\Modules\AmmoFactory\Actions\ManageFactoryAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\AmmoFactory\Actions\ManageFactoryAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\AmmoFactory\Domain\AmmoFactoryService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(\Netmafia\Modules\Buildings\Domain\BuildingService::class),
            $c->get(Connection::class)
        );
    },

    // Countries Module
    \Netmafia\Modules\Countries\Domain\CountriesService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Countries\Domain\CountriesService(
            $c->get(\Netmafia\Modules\Buildings\Domain\BuildingService::class),
            $c->get(\Netmafia\Modules\AmmoFactory\Domain\AmmoFactoryService::class),
            $c->get(\Netmafia\Modules\Buildings\Domain\HospitalService::class),
            $c->get(CacheService::class)
        );
    },

    \Netmafia\Modules\Countries\Actions\CountriesAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Countries\Actions\CountriesAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Countries\Domain\CountriesService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class)
        );
    },

    // Home Module (Otthon)
    \Netmafia\Modules\Home\Domain\PropertyService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Home\Domain\PropertyService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(\Netmafia\Modules\Garage\Domain\VehicleRepository::class)
        );
    },

    \Netmafia\Modules\Home\Domain\SleepService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Home\Domain\SleepService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Home\Domain\PropertyService::class),
            $c->get(\Netmafia\Modules\Health\Domain\HealthService::class)
        );
    },

    // [2026-02-28] FIX: SessionService hozzáadva
    \Netmafia\Modules\Home\Actions\HomeAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Home\Actions\HomeAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Home\Domain\PropertyService::class),
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(\Netmafia\Modules\Item\Domain\InventoryService::class),
            $c->get(\Netmafia\Modules\Item\Domain\BuffService::class),
            $c->get(SessionService::class)
        );
    },

    // [2026-02-28] FIX: AuthService + SessionService hozzáadva
    \Netmafia\Modules\Home\Actions\SleepStartAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Home\Actions\SleepStartAction(
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(SessionService::class)
        );
    },

    // [2026-02-28] FIX: SessionService hozzáadva
    \Netmafia\Modules\Home\Actions\SleepWakeAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Home\Actions\SleepWakeAction(
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class),
            $c->get(SessionService::class)
        );
    },

    // [2026-02-28] FIX: AuthService + SessionService hozzáadva
    \Netmafia\Modules\Home\Actions\PropertyPurchaseAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Home\Actions\PropertyPurchaseAction(
            $c->get(\Netmafia\Modules\Home\Domain\PropertyService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(SessionService::class)
        );
    },

    // [2026-02-28] FIX: SessionService hozzáadva
    \Netmafia\Modules\Home\Actions\PropertySellAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Home\Actions\PropertySellAction(
            $c->get(\Netmafia\Modules\Home\Domain\PropertyService::class),
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class),
            $c->get(SessionService::class)
        );
    },

    // Item Module (Tárgyak)
    \Netmafia\Modules\Item\Domain\InventoryService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Item\Domain\InventoryService(
            $c->get(Connection::class)
        );
    },

    \Netmafia\Modules\Item\Domain\BuffService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Item\Domain\BuffService(
            $c->get(Connection::class)
        );
    },

    \Netmafia\Modules\Item\Domain\ItemService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Item\Domain\ItemService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Item\Domain\InventoryService::class),
            $c->get(\Netmafia\Modules\Item\Domain\BuffService::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(HealthService::class)
        );
    },

    // [2026-02-28] FIX: SessionService hozzáadva
    \Netmafia\Modules\Item\Actions\EquipItemAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Item\Actions\EquipItemAction(
            $c->get(\Netmafia\Modules\Item\Domain\ItemService::class),
            $c->get(SessionService::class)
        );
    },

    // [2026-02-28] FIX: SessionService hozzáadva
    \Netmafia\Modules\Item\Actions\UnequipItemAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Item\Actions\UnequipItemAction(
            $c->get(\Netmafia\Modules\Item\Domain\ItemService::class),
            $c->get(SessionService::class)
        );
    },

    // [2026-02-28] FIX: SessionService hozzáadva
    \Netmafia\Modules\Item\Actions\UseItemAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Item\Actions\UseItemAction(
            $c->get(\Netmafia\Modules\Item\Domain\ItemService::class),
            $c->get(SessionService::class)
        );
    },

    // [2026-02-28] FIX: SessionService hozzáadva
    \Netmafia\Modules\Item\Actions\SellItemAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Item\Actions\SellItemAction(
            $c->get(\Netmafia\Modules\Item\Domain\ItemService::class),
            $c->get(SessionService::class)
        );
    },

    // Garage Module
    \Netmafia\Modules\Garage\Domain\VehicleRepository::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Garage\Domain\VehicleRepository(
            $c->get(Connection::class)
        );
    },

    \Netmafia\Modules\Garage\Domain\TuningService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Garage\Domain\TuningService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(\Netmafia\Modules\Garage\Domain\VehicleRepository::class)
        );
    },

    \Netmafia\Modules\Garage\Domain\GarageService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Garage\Domain\GarageService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Garage\Domain\VehicleRepository::class),
            $c->get(AuditLogger::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(\Netmafia\Modules\Home\Domain\PropertyService::class)
        );
    },

    \Netmafia\Modules\Game\Actions\GameViewAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Game\Actions\GameViewAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(\Netmafia\Modules\Game\Domain\NewsService::class)
        );
    },

    \Netmafia\Modules\Garage\Actions\GarageListAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Garage\Actions\GarageListAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(AuditLogger::class),
            $c->get(\Netmafia\Modules\Garage\Domain\GarageService::class)
        );
    },

    \Netmafia\Modules\Garage\Actions\GarageExpandAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Garage\Actions\GarageExpandAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(AuditLogger::class),
            $c->get(\Netmafia\Modules\Garage\Domain\GarageService::class)
        );
    },

    \Netmafia\Modules\Garage\Actions\GarageBuySlotAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Garage\Actions\GarageBuySlotAction(
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(AuditLogger::class),
            $c->get(\Netmafia\Infrastructure\SessionService::class)
        );
    },

    \Netmafia\Modules\Garage\Actions\GarageSellSlotAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Garage\Actions\GarageSellSlotAction(
            $c->get(\Netmafia\Modules\Garage\Domain\VehicleRepository::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(Connection::class),
            $c->get(AuditLogger::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class)
        );
    },

    \Netmafia\Modules\Garage\Actions\VehicleDetailsAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Garage\Actions\VehicleDetailsAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Garage\Domain\VehicleRepository::class),
            $c->get(\Netmafia\Modules\Garage\Domain\TuningService::class)
        );
    },

    \Netmafia\Modules\Garage\Actions\TuneVehicleAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Garage\Actions\TuneVehicleAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Garage\Domain\TuningService::class),
            $c->get(\Netmafia\Modules\Garage\Domain\VehicleRepository::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class)
        );
    },

    \Netmafia\Modules\Garage\Actions\GarageSellConfirmAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Garage\Actions\GarageSellConfirmAction(
            $c->get(\Netmafia\Modules\Garage\Domain\VehicleRepository::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(Twig::class)
        );
    },

    // Shop Module
    \Netmafia\Modules\Shop\Domain\ShopService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Shop\Domain\ShopService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Item\Domain\InventoryService::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class)
        );
    },

    \Netmafia\Modules\Shop\Actions\ShopViewAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Shop\Actions\ShopViewAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Shop\Domain\ShopService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class)
        );
    },

    \Netmafia\Modules\Shop\Actions\ShopBuyAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Shop\Actions\ShopBuyAction(
            $c->get(\Netmafia\Modules\Shop\Domain\ShopService::class)
        );
    },

    \Netmafia\Modules\Shop\Actions\ShopAdminCreateAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Shop\Actions\ShopAdminCreateAction(
            $c->get(\Netmafia\Modules\Shop\Domain\ShopService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(Twig::class)
        );
    },

    // Car Theft Module
    \Netmafia\Modules\CarTheft\Domain\CarTheftService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\CarTheft\Domain\CarTheftService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Health\Domain\HealthService::class),
            $c->get(\Netmafia\Modules\Xp\Domain\XpService::class),
            $c->get(\Netmafia\Modules\Garage\Domain\VehicleRepository::class),
            $c->get(AuditLogger::class),
            $c->get(\Netmafia\Modules\Item\Domain\BuffService::class),
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class)
        );
    },
    \Netmafia\Modules\CarTheft\Actions\CarTheftIndexAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\CarTheft\Actions\CarTheftIndexAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class)
        );
    },
    \Netmafia\Modules\CarTheft\Actions\CarTheftStreetAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\CarTheft\Actions\CarTheftStreetAction(
            $c->get(\Slim\Views\Twig::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(\Netmafia\Modules\CarTheft\Domain\CarTheftService::class),
            $c->get(\Netmafia\Infrastructure\SessionService::class),
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class)
        );
    },
    \Netmafia\Modules\CarTheft\Actions\CarTheftAttemptAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\CarTheft\Actions\CarTheftAttemptAction(
            $c->get(\Netmafia\Modules\CarTheft\Domain\CarTheftService::class),
            $c->get(SessionService::class)
        );
    },
    \Netmafia\Modules\CarTheft\Actions\CarTheftDealershipAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\CarTheft\Actions\CarTheftDealershipAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(\Netmafia\Modules\CarTheft\Domain\CarTheftService::class),
            $c->get(SessionService::class),
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class)
        );
    },

    // Organized Crime Module
    \Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(\Netmafia\Modules\Xp\Domain\XpService::class),
            $c->get(HealthService::class),
            $c->get(\Netmafia\Infrastructure\CacheService::class),
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class),
            $c->get(\Netmafia\Modules\Notifications\Domain\NotificationService::class)
        );
    },

    // ECrime Service (with BuffService for cooldown_reduction)
    \Netmafia\Modules\ECrime\Domain\ECrimeService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\ECrime\Domain\ECrimeService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(HealthService::class),
            $c->get(\Netmafia\Modules\Xp\Domain\XpService::class),
            $c->get(\Netmafia\Modules\Item\Domain\BuffService::class),
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class),
            $c->get(\Netmafia\Modules\Credits\Domain\CreditService::class)
        );
    },

    \Netmafia\Modules\ECrime\Domain\HackService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\ECrime\Domain\HackService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Health\Domain\HealthService::class),
            $c->get(\Netmafia\Modules\Xp\Domain\XpService::class),
            $c->get(\Netmafia\Modules\Item\Domain\BuffService::class)
        );
    },

    \Netmafia\Modules\ECrime\Actions\HackDevelopAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\ECrime\Actions\HackDevelopAction(
            $c->get(\Netmafia\Modules\ECrime\Domain\HackService::class),
            $c->get(\Netmafia\Infrastructure\SessionService::class)
        );
    },

    \Netmafia\Modules\ECrime\Actions\HackDistributeAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\ECrime\Actions\HackDistributeAction(
            $c->get(\Netmafia\Modules\ECrime\Domain\HackService::class),
            $c->get(\Netmafia\Infrastructure\SessionService::class)
        );
    },

    \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeIndexAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeIndexAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(\Netmafia\Modules\Home\Domain\SleepService::class)
        );
    },

    \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeSquadAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeSquadAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class)
        );
    },

    \Netmafia\Modules\OrganizedCrime\Domain\CrimeRequirementsValidator::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\OrganizedCrime\Domain\CrimeRequirementsValidator(
            $c->get(Connection::class)
        );
    },

    \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeInviteAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeInviteAction(
            $c->get(\Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService::class),
            $c->get(\Netmafia\Modules\OrganizedCrime\Domain\CrimeRequirementsValidator::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(Twig::class)
        );
    },

    \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeMemberAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeMemberAction(
            $c->get(\Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService::class),
            $c->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Notifications\Domain\NotificationService::class)
        );
    },

    \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeExecutionAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeExecutionAction(
            $c->get(\Netmafia\Modules\OrganizedCrime\Domain\OrganizedCrimeService::class),
            $c->get(Twig::class)
        );
    },

    // Market Module
    \Netmafia\Modules\Market\Domain\MarketService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Market\Domain\MarketService(
            $c->get(\Doctrine\DBAL\Connection::class),
            $c->get(\Netmafia\Modules\Item\Domain\InventoryService::class),
            $c->get(\Netmafia\Modules\Money\Domain\MoneyService::class),
            $c->get(\Netmafia\Modules\Credits\Domain\CreditService::class),
            $c->get(\Netmafia\Modules\AmmoFactory\Domain\BulletService::class),
            $c->get(\Netmafia\Infrastructure\CacheService::class),
            $c->get(AuditLogger::class),
            $c->get(\Netmafia\Modules\Notifications\Domain\NotificationService::class),
            $c->get(\Netmafia\Modules\Garage\Domain\VehicleRepository::class)
        );
    },
    \Netmafia\Modules\Market\Actions\MarketIndexAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Market\Actions\MarketIndexAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Market\Domain\MarketService::class)
        );
    },
    \Netmafia\Modules\Market\Actions\MarketSellCategoryAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Market\Actions\MarketSellCategoryAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Market\Domain\MarketService::class)
        );
    },
    \Netmafia\Modules\Market\Actions\MarketSellItemSelectAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Market\Actions\MarketSellItemSelectAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Market\Domain\MarketService::class)
        );
    },
    \Netmafia\Modules\Market\Actions\MarketSellSubmitAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Market\Actions\MarketSellSubmitAction(
            $c->get(\Netmafia\Modules\Market\Domain\MarketService::class)
        );
    },
    \Netmafia\Modules\Market\Actions\MarketListCategoryAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Market\Actions\MarketListCategoryAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Market\Domain\MarketService::class)
        );
    },
    \Netmafia\Modules\Market\Actions\MarketItemDetailsAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Market\Actions\MarketItemDetailsAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Market\Domain\MarketService::class)
        );
    },
    \Netmafia\Modules\Market\Actions\MarketSellFormAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Market\Actions\MarketSellFormAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Market\Domain\MarketService::class)
        );
    },
    \Netmafia\Modules\Market\Actions\MarketBuySubmitAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Market\Actions\MarketBuySubmitAction(
            $c->get(\Netmafia\Modules\Market\Domain\MarketService::class)
        );
    },

    // =========================================================================
    // Forum Module
    // =========================================================================
    \Netmafia\Modules\Forum\Domain\ForumService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Forum\Domain\ForumService(
            $c->get(Connection::class)
        );
    },
    \Netmafia\Modules\Forum\Actions\ForumIndexAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Forum\Actions\ForumIndexAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Forum\Domain\ForumService::class)
        );
    },
    \Netmafia\Modules\Forum\Actions\ForumTopicListAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Forum\Actions\ForumTopicListAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Forum\Domain\ForumService::class)
        );
    },
    \Netmafia\Modules\Forum\Actions\ForumTopicViewAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Forum\Actions\ForumTopicViewAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Forum\Domain\ForumService::class)
        );
    },
    \Netmafia\Modules\Forum\Actions\ForumPostCreateAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Forum\Actions\ForumPostCreateAction(
            $c->get(\Netmafia\Modules\Forum\Domain\ForumService::class)
        );
    },
    \Netmafia\Modules\Forum\Actions\ForumTopicCreateAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Forum\Actions\ForumTopicCreateAction(
            $c->get(Twig::class),
            $c->get(\Netmafia\Modules\Forum\Domain\ForumService::class)
        );
    },
    \Netmafia\Modules\Forum\Actions\ForumCategoryCreateAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Forum\Actions\ForumCategoryCreateAction(
            $c->get(\Netmafia\Modules\Forum\Domain\ForumService::class)
        );
    },
    \Netmafia\Modules\Forum\Actions\ForumAdminAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Forum\Actions\ForumAdminAction(
            $c->get(\Netmafia\Modules\Forum\Domain\ForumService::class)
        );
    },

    // =========================================================================
    // Weed Module
    // =========================================================================
    \Netmafia\Modules\Weed\Domain\WeedService::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Weed\Domain\WeedService(
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Item\Domain\InventoryService::class)
        );
    },
    \Netmafia\Modules\Weed\Actions\WeedIndexAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Weed\Actions\WeedIndexAction(
            $c->get(Twig::class),
            $c->get(Connection::class),
            $c->get(\Netmafia\Modules\Item\Domain\InventoryService::class),
            $c->get(\Netmafia\Infrastructure\SessionService::class)
        );
    },
    \Netmafia\Modules\Weed\Actions\WeedPlantAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Weed\Actions\WeedPlantAction(
            $c->get(\Netmafia\Modules\Weed\Domain\WeedService::class),
            $c->get(\Netmafia\Infrastructure\SessionService::class)
        );
    },
    \Netmafia\Modules\Weed\Actions\WeedHarvestAction::class => function (ContainerInterface $c) {
        return new \Netmafia\Modules\Weed\Actions\WeedHarvestAction(
            $c->get(\Netmafia\Modules\Weed\Domain\WeedService::class),
            $c->get(\Netmafia\Infrastructure\SessionService::class)
        );
    },

    // Middlewares - PSR-15 compliant
    \Netmafia\Web\Middleware\AdminMiddleware::class => DI\autowire(),
];
