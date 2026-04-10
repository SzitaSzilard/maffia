<?php
declare(strict_types=1);

namespace Netmafia\Modules\Home\Actions;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Netmafia\Modules\Home\Domain\PropertyService;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Item\Domain\InventoryService;
use Netmafia\Modules\Item\Domain\BuffService;
use Netmafia\Infrastructure\SessionService;

class HomeAction
{
    private Twig $view;
    private PropertyService $propertyService;
    private SleepService $sleepService;
    private AuthService $authService;
    private InventoryService $inventoryService;
    private BuffService $buffService;
    // [2026-02-28] FIX: SessionService injektálva $_SESSION közvetlen használata helyett
    private SessionService $session;
    
    public function __construct(
        Twig $view,
        PropertyService $propertyService,
        SleepService $sleepService,
        AuthService $authService,
        InventoryService $inventoryService,
        BuffService $buffService,
        SessionService $session
    ) {
        $this->view = $view;
        $this->propertyService = $propertyService;
        $this->sleepService = $sleepService;
        $this->authService = $authService;
        $this->inventoryService = $inventoryService;
        $this->buffService = $buffService;
        $this->session = $session;
    }
    
    public function __invoke(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        
        // Friss user adatok betöltése DB-ből (mint a BankViewAction)
        $user = $this->authService->getAuthenticatedUser((int)$userId);
        $userCountry = $user['country_code'] ?? 'US';
        
        $activeTab = $request->getQueryParams()['tab'] ?? 'otthon';
        
        // Sleep státusz
        $sleepStatus = $this->sleepService->getSleepStatus(\Netmafia\Shared\Domain\ValueObjects\UserId::of($userId));
        
        // [2026-02-15] FIX: Automatikus felkelés az Action rétegben kezelve
        if ($sleepStatus && !empty($sleepStatus['should_wake_up'])) {
            $this->sleepService->wakeUp(\Netmafia\Shared\Domain\ValueObjects\UserId::of($userId));
            $sleepStatus = null;
        }
        
        // Friss remaining time számítása (minden page load-nál)
        if ($sleepStatus && isset($sleepStatus['sleep_end_at'])) {
            $now = new \DateTime();
            $endTime = new \DateTime($sleepStatus['sleep_end_at']);
            $diff = $endTime->getTimestamp() - $now->getTimestamp();
            $sleepStatus['seconds_remaining'] = max(0, $diff);
        }
        
        // Ingatlan check aktuális országban
        $propertyRegen = $this->propertyService->getSleepRegenerationForCountry(
            $userId,
            $userCountry
        );
        $hasPropertyInCountry = (($propertyRegen['health_regen_percent'] ?? 0) > 0 || ($propertyRegen['energy_regen_percent'] ?? 0) > 0);
        
        // Flash üzeneteket a FlashMiddleware + base_game.twig kezeli globálisan
        $viewData = [
            'active_tab'               => $activeTab,
            'user_country'             => $userCountry,
            'user'                     => $user,
            'sleep_status'             => $sleepStatus,
            'has_property_in_country'  => $hasPropertyInCountry,
            'property_regen'           => $propertyRegen
        ];

        
        // Ingatlanok tab adatok
        if ($activeTab === 'ingatlanok') {
            $viewData['available_properties'] = $this->propertyService->getAvailableProperties();
            $viewData['user_properties'] = $this->propertyService->getUserProperties($userId);
        }
        
        // Tárgyaid tab adatok
        if ($activeTab === 'targyaid') {
            $viewData['equipped_items'] = $this->inventoryService->getEquippedItems($userId);
            $viewData['stored_weapons'] = $this->inventoryService->getStoredWeapons($userId);
            $viewData['stored_armor'] = $this->inventoryService->getStoredArmor($userId);
            $viewData['stored_consumables'] = $this->inventoryService->getStoredConsumables($userId);
            $viewData['stored_misc'] = $this->inventoryService->getStoredMisc($userId);
            $viewData['active_buffs'] = $this->buffService->getActiveBuffs($userId);
        }
        
        return $this->view->render($response, 'home/index.twig', $viewData);
    }
}
