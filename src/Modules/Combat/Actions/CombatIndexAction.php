<?php
declare(strict_types=1);

namespace Netmafia\Modules\Combat\Actions;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Netmafia\Modules\Auth\Domain\AuthService;
use Netmafia\Modules\Combat\Domain\CombatRepository;
use Netmafia\Modules\Combat\Domain\CombatNarrator;
use Netmafia\Shared\Domain\RankCalculator;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Shared\Domain\ValueObjects\UserId;

class CombatIndexAction
{
    private Twig $twig;
    private AuthService $authService;
    private CombatRepository $repository;
    private \Netmafia\Modules\Item\Domain\ItemService $itemService;
    private \Netmafia\Modules\Item\Domain\InventoryService $inventoryService;
    private \Netmafia\Modules\Garage\Domain\VehicleRepository $vehicleRepository;
    private CombatNarrator $narrator;
    private \Netmafia\Modules\Item\Domain\BuffService $buffService;
    private SleepService $sleepService;

    public function __construct(
        Twig $twig, 
        AuthService $authService,
        CombatRepository $repository,
        \Netmafia\Modules\Item\Domain\ItemService $itemService,
        \Netmafia\Modules\Item\Domain\InventoryService $inventoryService,
        \Netmafia\Modules\Garage\Domain\VehicleRepository $vehicleRepository,
        CombatNarrator $narrator,
        \Netmafia\Modules\Item\Domain\BuffService $buffService,
        SleepService $sleepService
    ) {
        $this->twig = $twig;
        $this->authService = $authService;
        $this->repository = $repository;
        $this->itemService = $itemService;
        $this->inventoryService = $inventoryService;
        $this->vehicleRepository = $vehicleRepository;
        $this->narrator = $narrator;
        $this->buffService = $buffService;
        $this->sleepService = $sleepService;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id');
        $user = $this->authService->getAuthenticatedUser($userId);

        // Check if user is sleeping
        if ($this->sleepService->isUserSleeping(UserId::of((int)$userId))) {
            // Redirect to home or show error
            // For now, let's just return a simple error or redirect to 'home'
            // Assuming 302 redirect
            return $response->withHeader('Location', '/otthon')->withStatus(302);
            // Or better: $this->twig->render... with error? 
            // Redirect is safer to stop them.
        }

        // Mark all incoming attacks as read
        $this->repository->markAsRead($userId);

        // Check Attacker Cooldown
        $lastAttack = $this->repository->getLastAttackTime($userId);
        
        // Fetch cooldown reduction
        $cooldownReduction = $this->buffService->getActiveBonus($userId, 'cooldown_reduction', 'combat');
        
        $attackerCooldownRemaining = \Netmafia\Modules\Combat\Domain\CombatCalculator::calculateCooldownRemaining($lastAttack, 15, $cooldownReduction);

        // Calculate Rank limits for eligibility
        $rankInfo = RankCalculator::getRankInfo((int)$user['xp']);
        
        $canAccessCombat = ($rankInfo['index'] >= 3) || !empty($user['is_admin']);
        $requiredRankName = array_values(\Netmafia\Shared\RankConfig::RANKS)[3] ?? 'Gengszter';
        
        $allRanks = array_keys(\Netmafia\Shared\RankConfig::RANKS);
        sort($allRanks);
        $minXp = $rankInfo['minXp'];
        $maxXp = 999999999;
        
        foreach ($allRanks as $limit) {
            if ($limit > $minXp) {
                $maxXp = $limit;
                break;
            }
        }

        $targets = $this->repository->getAttackableUsers(
            $userId, 
            $user['country_code'], 
            $minXp, 
            $maxXp
        );
            
        // Add calculated rank/level to targets
        foreach ($targets as &$target) {
            $info = RankCalculator::getRankInfo((int)$target['xp']);
            $target['level'] = $info['index'] + 1;
            $target['rank_name'] = $info['name'];
        }
        unset($target);

        $history = $this->repository->getCombatHistory($userId);
        
        $attacks = [];
        $defenses = [];
        
        foreach ($history as &$log) { // Pass by ref to modify
             // Generate Narrative based on view perspective
             $isAttacker = ($log['attacker_id'] == $userId);
             $log['narrative'] = $this->narrator->generateNarrative($log, $isAttacker);

            if ($isAttacker) {
                $attacks[] = $log;
            } elseif ($log['defender_id'] == $userId) {
                $defenses[] = $log;
            }
        }
        unset($log);

        $settings = $this->repository->getCombatSettings($userId);
        $wins = $this->repository->getTotalWins($userId);
        $stats = $this->itemService->calculateUserStats($userId);

        // Stats are already calculated with buffs in ItemService
        // We need to pass the full stats array to access 'active_buffs' and bonus components in view
        // $stats = ['attack' => X, 'active_buffs' => [...], ...]
        
        // Add stats to user array for view compatibility (flat structure preferred by existing views?)
        // Or better, pass 'combat_stats' separately.
        // Existing view uses user.attack_points
        $user['attack_points'] = $stats['attack'];
        $user['defense_points'] = $stats['defense'];
        
        // Pass detailed stats for tooltips
        $viewData['combat_stats'] = $stats;

        // Get Default Vehicle for logic handling
        $userVehicles = $this->vehicleRepository->getUserVehicles($userId);
        $defaultVehicle = null;
        foreach ($userVehicles as $v) {
            if ($v['is_default']) {
                $defaultVehicle = $v;
                break;
            }
        }
        
        // Determine "Active" status for display
        // Active = Settings Enabled AND Has Vehicle AND Fuel >= 20
        $isVehicleActive = false;
        // Use 'fuel_amount' column
        if ($settings['use_vehicle'] && $defaultVehicle && ($defaultVehicle['fuel_amount'] ?? 0) >= 20) {
            $isVehicleActive = true;
        }

        $isAjax = $request->hasHeader('HX-Request');

        // Check for weapon and ammo
        $equippedItems = $this->inventoryService->getEquippedItems($userId);
        $hasWeapon = false;
        foreach ($equippedItems as $item) {
            if ($item['type'] === 'weapon') {
                $hasWeapon = true;
                break;
            }
        }
        $ammoCount = $user['bullets'] ?? 0;

        // Calculate Extra Defense Points from Ammo
        $extraDefensePoints = 0;
        $defAmmoSetting = (int)($settings['defense_ammo'] ?? 0);
        
        if ($defAmmoSetting > 0 && $ammoCount > 0) {
            $effectiveAmmo = min($ammoCount, $defAmmoSetting);
            $bonusPercent = \Netmafia\Modules\Combat\Domain\CombatCalculator::calculateDefenseBonus($effectiveAmmo);
            
            // Apply to base defense points
            $extraDefensePoints = $user['defense_points'] * ($bonusPercent / 100);
        }

        $viewData = [
            'user' => $user,
            'targets' => $targets,
            'history' => $history,
            'attacks' => $attacks,
            'defenses' => $defenses,
            'settings' => $settings,
            'rank_info' => $rankInfo,
            'total_wins' => $wins,
            'is_ajax' => $isAjax,
            'default_vehicle' => $defaultVehicle,
            'is_vehicle_active' => $isVehicleActive,
            'has_weapon' => $hasWeapon,
            'ammo_count' => $ammoCount,
            'attacker_cooldown' => $attackerCooldownRemaining,
            'extra_defense_points' => $extraDefensePoints,
            'combat_stats' => $stats,
            'can_access_combat' => $canAccessCombat,
            'required_rank_name' => $requiredRankName
        ];

        return $this->twig->render($response, 'combat/index.twig', $viewData);
    }
}
