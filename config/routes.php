<?php
declare(strict_types=1);

use Slim\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

return function (App $app) {
    
    // Public Routes
    $app->map(['GET', 'POST'], '/login', \Netmafia\Modules\Auth\Actions\LoginAction::class);
    $app->map(['GET', 'POST'], '/register', \Netmafia\Modules\Auth\Actions\RegisterAction::class);
    $app->map(['GET', 'POST'], '/logout', \Netmafia\Modules\Auth\Actions\LogoutAction::class);

    // Protected Routes (Require Authentication)
    $app->group('', function ($group) {
        
        $group->get('/', function (Request $request, Response $response) {
            return $response->withHeader('Location', '/game')->withStatus(302);
        });

        $group->get('/game', \Netmafia\Modules\Game\Actions\GameViewAction::class);
        $group->get('/game/user-stats', \Netmafia\Modules\Game\Actions\UserStatsAction::class);
        
        // Game News (HTMX)
        $group->get('/game/news/form', \Netmafia\Modules\Game\Actions\NewsFormAction::class);
        $group->post('/game/news/create', \Netmafia\Modules\Game\Actions\NewsCreateAction::class);
        $group->get('/game/news/{id}/edit', \Netmafia\Modules\Game\Actions\NewsEditFormAction::class);
        $group->post('/game/news/{id}/update', \Netmafia\Modules\Game\Actions\NewsUpdateAction::class);
        $group->post('/game/news/{id}/delete', \Netmafia\Modules\Game\Actions\NewsDeleteAction::class);
        $group->get('/game/news/{id}/delete-confirm', \Netmafia\Modules\Game\Actions\NewsDeleteConfirmAction::class);
        $group->get('/game/news/{id}/delete-cancel', \Netmafia\Modules\Game\Actions\NewsDeleteCancelAction::class);

        // Kocsma Module
        $group->get('/kocsma', \Netmafia\Modules\Kocsma\Actions\KocsmaViewAction::class);
        $group->post('/kocsma/chat', \Netmafia\Modules\Kocsma\Actions\KocsmaChatAction::class);

        // Forum Module
        $group->get('/forum', \Netmafia\Modules\Forum\Actions\ForumIndexAction::class);
        $group->get('/forum/kategoria/{id}', \Netmafia\Modules\Forum\Actions\ForumTopicListAction::class);
        $group->get('/forum/topic/{id}', \Netmafia\Modules\Forum\Actions\ForumTopicViewAction::class);
        $group->post('/forum/topic/{id}/valasz', \Netmafia\Modules\Forum\Actions\ForumPostCreateAction::class);
        $group->post('/forum/kategoria/{id}/uj-topic', \Netmafia\Modules\Forum\Actions\ForumTopicCreateAction::class);
        $group->post('/forum/admin/kategoria', \Netmafia\Modules\Forum\Actions\ForumCategoryCreateAction::class);
        $group->post('/forum/admin/{action}', \Netmafia\Modules\Forum\Actions\ForumAdminAction::class);

        // Messages Module
        $group->get('/uzenetek', \Netmafia\Modules\Messages\Actions\MessagesViewAction::class);
        $group->get('/uzenetek/{tab}', \Netmafia\Modules\Messages\Actions\MessagesViewAction::class);
        $group->post('/uzenetek/kuldes', \Netmafia\Modules\Messages\Actions\MessageSendAction::class);
        $group->post('/uzenetek/torles', \Netmafia\Modules\Messages\Actions\MessageDeleteAction::class);

        // Hospital
        $group->get('/korhaz', \Netmafia\Modules\Buildings\Actions\HospitalViewAction::class);
        $group->post('/korhaz/gyogyitas', \Netmafia\Modules\Buildings\Actions\HospitalHealAction::class);
        $group->map(['GET', 'POST'], '/korhaz/kezeles', \Netmafia\Modules\Buildings\Actions\HospitalManageAction::class);
    
    // Notifications Module
        $group->get('/ertesitesek', \Netmafia\Modules\Notifications\Actions\NotificationsViewAction::class);
        $group->post('/ertesitesek/torles', \Netmafia\Modules\Notifications\Actions\NotificationDeleteAction::class);

        // Bank Module
        $group->get('/bank', \Netmafia\Modules\Bank\Actions\BankViewAction::class)->setName('bank.index');
        $group->post('/bank/open', \Netmafia\Modules\Bank\Actions\BankOpenAction::class);
        $group->post('/bank/transaction', \Netmafia\Modules\Bank\Actions\BankTransactionAction::class);
        $group->post('/bank/transfer', \Netmafia\Modules\Bank\Actions\BankTransferAction::class);
        $group->get('/bank/history', \Netmafia\Modules\Bank\Actions\BankHistoryAction::class);

        // Restaurant Module
        $group->get('/restaurant', \Netmafia\Modules\Buildings\Actions\RestaurantViewAction::class)->setName('restaurant.index');
        $group->post('/restaurant/consume', \Netmafia\Modules\Buildings\Actions\RestaurantConsumeAction::class);
        
        // Hungarian aliases
        $group->get('/etterem', \Netmafia\Modules\Buildings\Actions\RestaurantViewAction::class);
        $group->post('/etterem/fogyasztas', \Netmafia\Modules\Buildings\Actions\RestaurantConsumeAction::class);
        $group->get('/etterem/kezeles', \Netmafia\Modules\Buildings\Actions\RestaurantManageAction::class);
        $group->post('/etterem/frissites', \Netmafia\Modules\Buildings\Actions\RestaurantUpdateAction::class);
        
        // Posta Module
        $group->get('/posta', \Netmafia\Modules\Postal\Actions\PostalViewAction::class);
        $group->get('/posta/targyak/{category}', \Netmafia\Modules\Postal\Actions\PostalCategoryItemsAction::class);
        $group->post('/posta/kosar/hozzaad', \Netmafia\Modules\Postal\Actions\PostalAddToCartAction::class);
        $group->post('/posta/kosar/torol', \Netmafia\Modules\Postal\Actions\PostalRemoveFromCartAction::class);
        $group->post('/posta/kosar/torles-mind', \Netmafia\Modules\Postal\Actions\PostalClearCartAction::class);
        $group->post('/posta/kuldes', \Netmafia\Modules\Postal\Actions\PostalSendAction::class);
        $group->post('/posta/csomag/torol/{id}', \Netmafia\Modules\Postal\Actions\PostalCancelAction::class);



        // Gangster Search
        $group->get('/gengszter-kereses', \Netmafia\Modules\Search\Actions\SearchAction::class);

        // Ammo Factory
        $group->map(['GET', 'POST'], '/toltenygyar', \Netmafia\Modules\AmmoFactory\Actions\AmmoFactoryAction::class);
        $group->map(['GET', 'POST'], '/toltenygyar/kezel', \Netmafia\Modules\AmmoFactory\Actions\ManageFactoryAction::class);

        // Online Gangsters
        $group->get('/online', \Netmafia\Modules\Online\Actions\OnlineAction::class);
        $group->get('/online-gengszterek', \Netmafia\Modules\Online\Actions\OnlineAction::class);

        // Home / Otthon Module
        $group->get('/otthon', \Netmafia\Modules\Home\Actions\HomeAction::class);
        $group->post('/otthon/ingatlan/vasarlas', \Netmafia\Modules\Home\Actions\PropertyPurchaseAction::class);
        $group->post('/otthon/ingatlan/eladas', \Netmafia\Modules\Home\Actions\PropertySellAction::class);
        $group->post('/otthon/alvas/lefekes', \Netmafia\Modules\Home\Actions\SleepStartAction::class);
        $group->post('/otthon/alvas/felkeles', \Netmafia\Modules\Home\Actions\SleepWakeAction::class);

        // Item Module (Tárgyak)
        $group->post('/targy/felszerel', \Netmafia\Modules\Item\Actions\EquipItemAction::class);
        $group->post('/targy/levesz', \Netmafia\Modules\Item\Actions\UnequipItemAction::class);
        $group->post('/targy/hasznal', \Netmafia\Modules\Item\Actions\UseItemAction::class);
        $group->post('/targy/elad', \Netmafia\Modules\Item\Actions\SellItemAction::class);

        // Garage Module
        $group->get('/garazs', \Netmafia\Modules\Garage\Actions\GarageListAction::class);
        $group->get('/garazs/partials/container', \Netmafia\Modules\Garage\Actions\GarageContainerPartialAction::class);
        $group->post('/garazs/batch/quick-sell', \Netmafia\Modules\Garage\Actions\VehiclesQuickSellBatchAction::class);
        $group->get('/garazs/bovites', \Netmafia\Modules\Garage\Actions\GarageExpandAction::class);
        $group->post('/garazs/bovites/vasarlas', \Netmafia\Modules\Garage\Actions\GarageBuySlotAction::class);
        $group->post('/garazs/bovites/eladas', \Netmafia\Modules\Garage\Actions\GarageSellSlotAction::class);
        $group->get('/garazs/bovites/eladas/confirm', \Netmafia\Modules\Garage\Actions\GarageSellConfirmAction::class);
        $group->get('/garazs/vehicle/{id}', \Netmafia\Modules\Garage\Actions\VehicleDetailsAction::class);
        $group->post('/garazs/vehicle/{id}/move', \Netmafia\Modules\Garage\Actions\VehicleMoveAction::class);
        $group->post('/garazs/vehicle/{id}/default', \Netmafia\Modules\Garage\Actions\VehicleSetDefaultAction::class);
        $group->post('/garazs/vehicle/{id}/unsetDefault', \Netmafia\Modules\Garage\Actions\VehicleUnsetDefaultAction::class);
        $group->post('/garazs/vehicle/{id}/tune', \Netmafia\Modules\Garage\Actions\TuneVehicleAction::class);
        $group->post('/garazs/vehicle/{id}/quick-sell', \Netmafia\Modules\Garage\Actions\VehicleQuickSellAction::class);
        $group->get('/garazs/vehicle/{id}/quick-sell/confirm', \Netmafia\Modules\Garage\Actions\VehicleQuickSellConfirmAction::class);
        $group->post('/garazs/vehicle/{id}/security-upgrade', \Netmafia\Modules\Garage\Actions\VehicleBuyUpgradeAction::class);
        $group->post('/garazs/vehicle/{id}/repair', \Netmafia\Modules\Garage\Actions\VehicleRepairAction::class);

        // Countries / Buildings List
        $group->get('/orszagok', \Netmafia\Modules\Countries\Actions\CountriesAction::class);
        
        // Highway / Travel Module
        $group->get('/utazas', \Netmafia\Modules\Buildings\Actions\HighwayViewAction::class);
     	// Highway / Travel
	$group->get('/epulet/autopalya', \Netmafia\Modules\Buildings\Actions\HighwayViewAction::class);
	$group->post('/epulet/autopalya/utaz', \Netmafia\Modules\Buildings\Actions\HighwayTravelAction::class);
	$group->post('/epulet/autopalya/matrica', \Netmafia\Modules\Buildings\Actions\HighwayStickerAction::class);
	$group->get('/epulet/autopalya/kezel', \Netmafia\Modules\Buildings\Actions\HighwayManageAction::class);
	$group->post('/epulet/autopalya/kezel', \Netmafia\Modules\Buildings\Actions\HighwayUpdateAction::class);

	// Airplane Travel
	$group->post('/repulo/utaz', \Netmafia\Modules\Buildings\Actions\AirplaneTravelAction::class);
	$group->get('/repulo/kezel', \Netmafia\Modules\Buildings\Actions\AirportManageAction::class);
	$group->post('/repulo/frissites', \Netmafia\Modules\Buildings\Actions\AirportUpdateAction::class);

        // Combat Module
        $group->get('/kuzdelmek', \Netmafia\Modules\Combat\Actions\CombatIndexAction::class)->setName('combat.index');
        $group->post('/kuzdelmek/tamadas/{id}', \Netmafia\Modules\Combat\Actions\CombatAttackAction::class);
        $group->post('/kuzdelmek/beallitasok', \Netmafia\Modules\Combat\Actions\CombatSettingsAction::class);

        // Shop Module
        $group->get('/vasarlas', \Netmafia\Modules\Shop\Actions\ShopViewAction::class);
        $group->post('/vasarlas/buy', \Netmafia\Modules\Shop\Actions\ShopBuyAction::class);
        $group->map(['GET', 'POST'], '/vasarlas/admin', \Netmafia\Modules\Shop\Actions\ShopAdminCreateAction::class);

        // Car Theft Module
        $group->get('/autolopas', \Netmafia\Modules\CarTheft\Actions\CarTheftIndexAction::class);
        $group->get('/autolopas/utca', \Netmafia\Modules\CarTheft\Actions\CarTheftStreetAction::class);
        $group->post('/autolopas/kiserlet', \Netmafia\Modules\CarTheft\Actions\CarTheftAttemptAction::class);
        $group->get('/autolopas/szalon', [\Netmafia\Modules\CarTheft\Actions\CarTheftDealershipAction::class, 'show']);
        $group->post('/autolopas/szalon', [\Netmafia\Modules\CarTheft\Actions\CarTheftDealershipAction::class, 'attempt']);

        // Organized Crime Module
        $group->get('/szervezett-bunozes', \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeIndexAction::class);
        $group->get('/szervezett-bunozes/csapat', \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeSquadAction::class);
        $group->post('/szervezett-bunozes/meghivas', \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeInviteAction::class);
        $group->post('/szervezett-bunozes/kezdes', \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeKezdesAction::class);
        $group->post('/szervezett-bunozes/validacio', \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeValidateAction::class);

        // e-Bűnözés Module
        $group->get('/e-bunozes', \Netmafia\Modules\ECrime\Actions\ECrimeIndexAction::class);
        $group->get('/e-bunozes/{tab}', \Netmafia\Modules\ECrime\Actions\ECrimeIndexAction::class);
        $group->post('/e-bunozes/shop', \Netmafia\Modules\ECrime\Actions\ECrimeShopAction::class);
        $group->post('/e-bunozes/atveres-vegrehajtas', \Netmafia\Modules\ECrime\Actions\ECrimeExecuteScamAction::class);
        $group->post('/e-bunozes/hackeles/fejlesztes', \Netmafia\Modules\ECrime\Actions\HackDevelopAction::class);
        $group->post('/e-bunozes/hackeles/terjesztes', \Netmafia\Modules\ECrime\Actions\HackDistributeAction::class);

        // Kisstílű Bűnözés Module
        $group->get('/kisstilubunozes', \Netmafia\Modules\PettyCrime\Actions\PettyCrimeIndexAction::class);
        $group->get('/kisstilubunozes/{tab}', \Netmafia\Modules\PettyCrime\Actions\PettyCrimeIndexAction::class);
        $group->post('/kisstilubunozes/felterkepez', \Netmafia\Modules\PettyCrime\Actions\PettyCrimeScanAction::class);
        $group->post('/kisstilubunozes/elkovet', \Netmafia\Modules\PettyCrime\Actions\PettyCrimeCommitAction::class);
        // Alias — kötőjeles URL is működjön (régi menü link)
        $group->get('/kisstilu-bunozes', \Netmafia\Modules\PettyCrime\Actions\PettyCrimeIndexAction::class);
        $group->get('/kisstilu-bunozes/{tab}', \Netmafia\Modules\PettyCrime\Actions\PettyCrimeIndexAction::class);


        $group->post('/szervezett-bunozes/elfogad', [\Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeMemberAction::class, 'accept']);
        $group->post('/szervezett-bunozes/elutasit', [\Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeMemberAction::class, 'decline']);
        $group->post('/szervezett-bunozes/visszavon', [\Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeMemberAction::class, 'revoke']);
        $group->post('/szervezett-bunozes/kilepes', [\Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeMemberAction::class, 'leave']);
        $group->post('/szervezett-bunozes/feloszlatas', [\Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeMemberAction::class, 'disband']);
        $group->post('/szervezett-bunozes/elinditas', \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeExecutionAction::class);
        $group->post('/szervezett-bunozes/jarmu-valasztas', \Netmafia\Modules\OrganizedCrime\Actions\OrganizedCrimeVehicleAction::class);

        // Market Module

        $group->get('/piac', \Netmafia\Modules\Market\Actions\MarketIndexAction::class);
        $group->get('/piac/uj-aru', \Netmafia\Modules\Market\Actions\MarketSellFormAction::class);
        $group->get('/piac/eladasaid', \Netmafia\Modules\Market\Actions\MarketUserListingsAction::class);
        $group->post('/piac/visszavon/{id}', \Netmafia\Modules\Market\Actions\MarketRevokeListingAction::class);
        $group->get('/piac/eladas/kategoria/{category}', \Netmafia\Modules\Market\Actions\MarketSellCategoryAction::class);
        $group->post('/piac/eladas/konfigural', \Netmafia\Modules\Market\Actions\MarketSellItemSelectAction::class);
        $group->post('/piac/eladas/bekuld', \Netmafia\Modules\Market\Actions\MarketSellSubmitAction::class);
        $group->post('/piac/vasarlas/{id}', \Netmafia\Modules\Market\Actions\MarketBuySubmitAction::class);
        $group->get('/piac/lista/{category}', \Netmafia\Modules\Market\Actions\MarketListCategoryAction::class);
        $group->get('/piac/lista/{category}/{id}', \Netmafia\Modules\Market\Actions\MarketItemDetailsAction::class);

        // Weed Module
        $group->get('/vadkender', \Netmafia\Modules\Weed\Actions\WeedIndexAction::class);
        $group->post('/vadkender/ultet', \Netmafia\Modules\Weed\Actions\WeedPlantAction::class);
        $group->post('/vadkender/szuret', \Netmafia\Modules\Weed\Actions\WeedHarvestAction::class);

        // Profile (ID vagy Felhasználónév alapján)
        $group->get('/profil/{identifier}', \Netmafia\Modules\Profile\Actions\ProfileAction::class);

        // Placeholder for missing features OR Profile (Root Dispatcher)
        // Checks if slug is a User -> Profile, otherwise -> Under Construction
        $group->get('/{slug}', \Netmafia\Web\Actions\RootDispatcherAction::class);

    })->add(new \Netmafia\Web\Middleware\LastActivityMiddleware(
        $app->getContainer()->get(\Doctrine\DBAL\Connection::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\SessionService::class)
    ))->add(new \Netmafia\Web\Middleware\HtmxMiddleware(
        $app->getContainer()->get(\Slim\Views\Twig::class)
    ))->add(new \Netmafia\Web\Middleware\UnreadNotificationsMiddleware(
        $app->getContainer()->get(\Doctrine\DBAL\Connection::class),
        $app->getContainer()->get(\Netmafia\Modules\Notifications\Domain\NotificationService::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\CacheService::class),
        $app->getContainer()->get(\Slim\Views\Twig::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\SessionService::class)
    ))->add(new \Netmafia\Web\Middleware\UnreadCombatMiddleware(
        $app->getContainer()->get(\Doctrine\DBAL\Connection::class),
        $app->getContainer()->get(\Slim\Views\Twig::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\SessionService::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\CacheService::class)
    ))->add(new \Netmafia\Web\Middleware\UnreadMessagesMiddleware(
        $app->getContainer()->get(\Netmafia\Modules\Messages\Domain\MessageService::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\CacheService::class),
        $app->getContainer()->get(\Slim\Views\Twig::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\SessionService::class)
    ))->add(new \Netmafia\Web\Middleware\OrganizedCrimeNotificationMiddleware(
        $app->getContainer()->get(\Doctrine\DBAL\Connection::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\CacheService::class),
        $app->getContainer()->get(\Slim\Views\Twig::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\SessionService::class)
    ))->add(new \Netmafia\Web\Middleware\SessionTimeoutMiddleware(
        $app->getContainer()->get(\Netmafia\Infrastructure\SessionService::class),
        15, // 15 perc inaktivitás után kilép
        $app->getContainer()->get(\Netmafia\Infrastructure\AuditLogger::class)
    ))->add(new \Netmafia\Web\Middleware\AuthMiddleware(
        $app->getContainer()->get(\Netmafia\Modules\Auth\Domain\AuthService::class),
        $app->getContainer()->get(\Netmafia\Infrastructure\SessionService::class)
    ));
};
