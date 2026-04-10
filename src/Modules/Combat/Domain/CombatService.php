<?php
declare(strict_types=1);

namespace Netmafia\Modules\Combat\Domain;

use Netmafia\Modules\AmmoFactory\Domain\BulletService;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Domain\RankCalculator;
use Netmafia\Modules\Item\Domain\InventoryService;
use Netmafia\Modules\Item\Domain\ItemService;
use Netmafia\Modules\Money\Domain\MoneyService;
use Netmafia\Modules\Health\Domain\HealthService;
use Netmafia\Modules\Xp\Domain\XpService;
use Netmafia\Modules\Garage\Domain\GarageService;
use Netmafia\Modules\Combat\Domain\CombatNarrator;
use Netmafia\Modules\Home\Domain\SleepService;

class CombatService
{
    private Connection $db;
    private CombatRepository $repository;
    private InventoryService $inventoryService;
    private ItemService $itemService;
    private MoneyService $moneyService;
    private HealthService $healthService;
    private XpService $xpService;
    private CombatNarrator $narrator;
    private \Netmafia\Modules\Item\Domain\BuffService $buffService;
    private SleepService $sleepService;
    private BulletService $bulletService;

    public function __construct(
        Connection $db,
        CombatRepository $repository,
        InventoryService $inventoryService,
        ItemService $itemService,
        MoneyService $moneyService,
        HealthService $healthService,
        XpService $xpService,
        CombatNarrator $narrator,
        \Netmafia\Modules\Item\Domain\BuffService $buffService,
        SleepService $sleepService,
        BulletService $bulletService
    ) {
        $this->db = $db;
        $this->repository = $repository;
        $this->inventoryService = $inventoryService;
        $this->itemService = $itemService;
        $this->moneyService = $moneyService;
        $this->healthService = $healthService;
        $this->xpService = $xpService;
        $this->narrator = $narrator;
        $this->buffService = $buffService;
        $this->sleepService = $sleepService;
        $this->bulletService = $bulletService;
    }

    /**
     * Harc végrehajtása
     */
    public function executeFight(int $attackerId, int $defenderId, int $attackerAmmo): array
    {
        if ($attackerId === $defenderId) {
            throw new GameException("Nem támadhatod meg magadat!");
        }

        // Check if attacker is sleeping
        if ($this->sleepService->isUserSleeping(UserId::of($attackerId))) {
            throw new GameException("Alszol! Előbb kelj fel, ha harcolni akarsz.");
        }

        // Check if defender is sleeping
        if ($this->sleepService->isUserSleeping(UserId::of($defenderId))) {
            throw new GameException("A célpont éppen alszik, nem támadhatod meg.");
        }

        if ($attackerAmmo < 0) $attackerAmmo = 0;
        if ($attackerAmmo > 999) $attackerAmmo = 999;

        $this->db->beginTransaction();
        try {
            // 1. Adatok betöltése (Lockoljuk a résztvevőket konzisztens sorrendben - Rule 2.7)
            $firstId = min($attackerId, $defenderId);
            $secondId = max($attackerId, $defenderId);

            $this->db->executeStatement("SELECT id FROM users WHERE id IN (?, ?) FOR UPDATE", [$firstId, $secondId]);

            $attacker = $this->db->fetchAssociative(
                "SELECT id, username, country_code, money, bullets, health, energy, xp, wins, losses, kills, last_activity
                 FROM users WHERE id = ?", [$attackerId]);
            $defender = $this->db->fetchAssociative(
                "SELECT id, username, country_code, money, bullets, health, energy, xp, wins, losses, kills, last_activity
                 FROM users WHERE id = ?", [$defenderId]);

            if (!$defender) throw new GameException("A célpont nem létezik.");
            if ($defender['health'] <= 0) throw new GameException("A célpont már halott.");
            if ($attacker['health'] <= 0) throw new GameException("Nem támadhatsz, mert halott vagy.");

            if ($attacker['country_code'] !== $defender['country_code']) {
                throw new GameException("Csak azonos országban lévő játékost támadhatsz!");
            }

            // --- Cooldown Checks (15 min) ---
            // Fetch cooldown reduction (e.g. Speed drug = 25%)
            $cooldownReduction = $this->buffService->getActiveBonus($attackerId, 'cooldown_reduction', 'combat');
            
            // Note: We still query 15 mins back to be safe, but the calculation will be dynamic.
            // Or better: Calculate effective window first?
            // Actually, querying 15 mins is fine, if we are stricter in the Calculator.
            // But if the user fought 12 mins ago, and has 25% reduction (11.25 min cooldown),
            // Calculator will return 0 remaining.
            
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $cooldownTime = $now->modify('-15 minutes')->format('Y-m-d H:i:s');

            // 1. Attacker Cooldown: Ha támadott az elmúlt 15 percben
            $lastAttack = $this->db->fetchOne(
                "SELECT created_at FROM combat_log WHERE attacker_id = ? AND created_at > ? ORDER BY created_at DESC LIMIT 1",
                [$attackerId, $cooldownTime]
            );
            
            if ($lastAttack) {
                $remainingSeconds = CombatCalculator::calculateCooldownRemaining($lastAttack, 15, $cooldownReduction);
                if ($remainingSeconds > 0) {
                    $minutes = floor($remainingSeconds / 60);
                    $seconds = $remainingSeconds % 60;
                    $timeStr = sprintf("%d perc %02d mp", $minutes, $seconds);
                     // User requested reduced waiting time.
                    throw new GameException("Még pihenned kell a legutóbbi támadásod után! ({$timeStr})");
                }
            }

            // 2. Defender Protection: Ha támadták az elmúlt 15 percben
            $lastDefense = $this->db->fetchOne(
                "SELECT created_at FROM combat_log WHERE defender_id = ? AND created_at > ? ORDER BY created_at DESC LIMIT 1",
                [$defenderId, $cooldownTime]
            );

            if ($lastDefense) {
                $remainingSeconds = CombatCalculator::calculateCooldownRemaining($lastDefense);
                if ($remainingSeconds > 0) {
                     $minutes = ceil($remainingSeconds / 60);
                     throw new GameException("A célpontot nemrég támadták meg, még védelem alatt áll. ({$minutes} perc)");
                }
            }
            // --------------------------------

            // Aktivitás ellenőrzés (15 perc) — Időzóna mentesítve
            $lastActive = new \DateTimeImmutable($defender['last_activity'], new \DateTimeZone('UTC'));
            if ($now->getTimestamp() - $lastActive->getTimestamp() > 15 * 60) {
                throw new GameException("A célpont nem volt aktív az elmúlt 15 percben.");
            }

            // Rang ellenőrzés
            $attackerRank = RankCalculator::getRankInfo((int)$attacker['xp']);
            $defenderRank = RankCalculator::getRankInfo((int)$defender['xp']);

            // Csak azonos 'szint' (rank index) támadható
            if ($attackerRank['index'] !== $defenderRank['index']) {
                throw new GameException("Csak veled azonos rangú játékost támadhatsz!");
            }

            // 2. Beállítások és Töltény ellenőrzés
            // Attacker ammo levonás
            if ($attackerAmmo > 0) {
                // [LEDGER] BulletService kezeli a levonást (balance_before/after log)
                $this->bulletService->useBullets(
                    UserId::of($attackerId),
                    $attackerAmmo,
                    'combat_use',
                    'Harci töltény használat (támadó)',
                    'combat_log', null
                );
            }

            // Defender settings
            $defenderSettings = $this->repository->getCombatSettings($defenderId);
            $defenderAmmo = 0;
            // [FIX] Védő töltényét CSAK beolvassuk, még NEM vonjuk le
            // A levonás csak akkor történik, ha tényleges harc lesz (nem menekülés)
            if ($defenderSettings['defense_ammo'] > 0) {
                 $defenderAmmo = min($defender['bullets'], $defenderSettings['defense_ammo']);
            }

            // 3. Statisztikák lekérése
            $attackerStats = $this->itemService->calculateUserStats($attackerId);
            $defenderStats = $this->itemService->calculateUserStats($defenderId);
            
            // Capture Weapon Name (assumed to be primary weapon or generic)
            // ItemService calculates stats but doesn't return weapon name easily?
            // Let's assume generic "fegyver" or try to fetch equipped weapon?
            // For now, let's look at inventory?
            // Simplify: Just use "fegyver" or hardcoded for now, OR fetch 'right_hand' item?
            // To fetch specific weapon name, we need InventoryService to return names.
            // Let's check getEquippedItems result in ItemService usage.
            // For now, let's define a placeholder variable to be filled.
            $attackerWeaponName = 'fegyver'; // Default
            $equipped = $this->inventoryService->getEquippedItems($attackerId);
            foreach($equipped as $item) {
                if (($item['type'] ?? '') === 'weapon') {
                    $attackerWeaponName = $item['name'];
                    break;
                }
            }
            
            $attackerWins = $this->repository->getTotalWins($attackerId);
            $defenderWins = $this->repository->getTotalWins($defenderId);

            // 4. Harci Erő Számítás (Új, valós statisztikákra épülő logika)
            // Ahhoz, hogy a fegyverzet lényeges legyen az XP-vel szemben, felszorozzuk a hatását.
            // A támadó Támadóerejét vesszük alapul, a védőnek pedig a Védőerejét.
            $attackerBasePower = ($attacker['xp'] * 0.05) + ($attackerStats['attack'] * 500) + ($attackerWins * 10);
            $defenderBasePower = ($defender['xp'] * 0.05) + ($defenderStats['defense'] * 500) + ($defenderWins * 10);

            // Célzási szerencse (RNG véletlenszerűség: +/- 10%)
            // Ezzel garantáljuk, hogy a harc nem 100%-ig kiszámítható
            $attackerRng = random_int(90, 110) / 100;
            $defenderRng = random_int(90, 110) / 100;

            $attackerScore = $attackerBasePower * $attackerRng;
            $defenderScore = $defenderBasePower * $defenderRng;
            
            // Ha 0 pont van, adjunk valami alap értéket, hogy a % érjen valamit?
            // Vagy a User által kért logika 0-4 skálán mozog.
            
            // Töltény bónusz (5-15%)
            // 999 ammo = 15%, 1 ammo = 5%? Vagy random?
            // User: "ezek adnak 5-15%ig esély növekedést"
            // Legyen lineáris: 1 db = 5%, 999 db = 15%
            
            if ($attackerAmmo > 0) {
                $bonusPercent = CombatCalculator::calculateAttackBonus($attackerAmmo);
                $attackerScore *= (1 + $bonusPercent / 100);
            }
            
            if ($defenderAmmo > 0) {
                 $bonusPercent = CombatCalculator::calculateDefenseBonus($defenderAmmo);
                 $defenderScore *= (1 + $bonusPercent / 100);
            }

            // 6. Jármű logika (Menekülés és Támadás bónusz)
            $escaped = false;
            $attVehicle = null;
            $defVehicle = null;
            
            // Attacker Bónusz (+5% ha van autó és be van kapcsolva)
            $attackerSettings = $this->repository->getCombatSettings($attackerId);
            
            if ($attackerSettings['use_vehicle']) {
                 $attVehicle = $this->db->fetchAssociative("SELECT uv.id, uv.fuel_amount, uv.damage_percent, v.name, v.speed, v.safety
                                                            FROM user_vehicles uv
                                                            JOIN vehicles v ON uv.vehicle_id = v.id
                                                            WHERE uv.user_id = ? AND uv.is_default = 1", [$attackerId]);
                 // Fuel check (min 20L) using 'fuel_amount'
                 if ($attVehicle && ($attVehicle['fuel_amount'] ?? 0) >= 20) {
                     // +5% bonus
                     $attackerScore *= 1.05;
                     
                     // Optional: Deduct fuel? User didn't specify, but implied similar mechanics.
                     // Let's deduct 1L for usage to be fair/realistic? Or standard 5L?
                     // Defender logic doesn't seemingly deduct fuel in current code (just checks).
                     // Let's stick to reading only for now unless specified.
                 }
            }
            // Defender menekülési esélye
            $defenderVehicleName = 'jármű';
            if ($defenderSettings['use_vehicle']) {
                // Check if has default car in garage and fuel > 20
                $defVehicle = $this->db->fetchAssociative("SELECT uv.id, uv.fuel_amount, uv.damage_percent, v.name, v.speed, v.safety
                                                           FROM user_vehicles uv
                                                           JOIN vehicles v ON uv.vehicle_id = v.id
                                                           WHERE uv.user_id = ? AND uv.is_default = 1", [$defenderId]);
                
                if ($defVehicle && ($defVehicle['fuel_amount'] ?? 0) >= 20) {
                     $defenderVehicleName = $defVehicle['name']; // Capture name
                     
                     // Ha vesztésre áll (Attacker Score > Defender Score)
                     if ($attackerScore > $defenderScore) {
                         // 10% esély menekülésre
                         if (random_int(1, 100) <= 10) {
                             $escaped = true;
                         }
                     }
                }
            }

            // Attacker üldözés
            // "Ha nyerésre állsz [Attacker] és az ellenfél menekül [Escaped], lehetőséged nyílik utánamenni"
            // Ehhez az Attackernek is kell autó
            // De ez a logika bonyolult UI oldalon ("lehetőség nyílik"). 
            // Egyszerűsítsük: Ha sikeres menekülés volt, harc vége (Döntetlen vagy Nincs eredmény).

            // 7. Eredmény
            $winnerId = null;
            $logData = [];
            if (!$escaped) {
                // [FIX] Védő töltényét CSAK MOST vonjuk le, ha tényleg volt harc
                // [LEDGER] BulletService kezeli a levonást (balance_before/after log)
                if ($defenderAmmo > 0) {
                    $this->bulletService->useBullets(
                        UserId::of($defenderId),
                        $defenderAmmo,
                        'combat_use',
                        'Harci töltény használat (védő)',
                        'combat_log', null
                    );
                }

                if ($attackerScore > $defenderScore) {
                    $winnerId = $attackerId;
                } elseif ($defenderScore > $attackerScore) {
                    $winnerId = $defenderId;
                } else {
                    // Döntetlen -> Random 50/50
                    $winnerId = (random_int(0, 1) === 0) ? $attackerId : $defenderId;
                }
            }

            $resultMessage = "";
            $moneyStolen = 0;
            $damage = 0;
            $attackerXpGain = 0;
            $defenderXpGain = 0;

            if ($escaped) {
                $resultMessage = "Az ellenfél elmenekült a járművével!";
                $logData['winner_id'] = null;
            } elseif ($winnerId) {
                $logData['winner_id'] = $winnerId;
                $loserId = ($winnerId === $attackerId) ? $defenderId : $attackerId;
                $isAttackerWinner = ($winnerId === $attackerId);

                // Loot (Pénz szerzés)
                // Csak ha nem menekült el
                $loserMoney = ($loserId === $attackerId) ? $attacker['money'] : $defender['money'];
                
                if ($loserMoney <= 100000) {
                    $percent = random_int(3, 12);
                } else {
                    $percent = 7;
                }
                
                $moneyStolen = (int)($loserMoney * ($percent / 100));
                
                if ($moneyStolen > 0) {
                     $this->moneyService->transferMoney(UserId::of($loserId), UserId::of($winnerId), $moneyStolen);
                }

                // Sebzés (Damage)
                // "győzelemmel ... megsebesítheted"
                // Mennyi sebzés? Legyen pl. 10-30 HP? Vagy fegyver függő?
                // User nem specifikálta, csak "megsebesítheted".
                // Használjunk egy alap értéket + fegyver stat?
                // Pl. Base 10 + (Attack - Defense difference)?
                // Egyszerűsítve: 20 HP fix.
                // Sebzés (Damage)
                // User kérése: random 12-27 között
                $damage = random_int(12, 27);
                
                // Sebzés alkalmazása a vesztesen
                $damageResult = $this->healthService->damage(UserId::of($loserId), $damage, 'combat', $winnerId);
                
                if ($damageResult['died']) {
                    $resultMessage .= " A vesztes meghalt a harcban!";
                    // Increment Kills for Winner
                    try {
                        $this->db->executeStatement("SET @audit_source = ?", ['CombatService::kill']);
                        $this->db->executeStatement("UPDATE users SET kills = kills + 1 WHERE id = ?", [$winnerId]);
                    } finally {
                        $this->db->executeStatement("SET @audit_source = NULL");
                    }
                }
                
                $logData['money_stolen'] = $moneyStolen;
                $logData['damage_dealt'] = $damage;

                // XP Kalkuláció a Modul Szabályzat szerint
                // - Támadó nyer: 8-12 XP, Veszít: 3-6 XP
                // - Védő nyer: 7-21 XP, Veszít: 7-11 XP
                // Döntetlen: 50% szerencse nyerésre/vesztésre (alapból is így számolódik)
                
                if ($isAttackerWinner) {
                    $attackerXpGain = random_int(8, 12);
                    $defenderXpGain = random_int(7, 11); // Védő veszít
                } else {
                    $attackerXpGain = random_int(3, 6);   // Támadó veszít
                    $defenderXpGain = random_int(7, 21); // Védő nyer
                }
                
                // XP Jóváírás az XpService-en keresztül (Szabályzat szerinti eljárás)
                $this->xpService->addXp(UserId::of($attackerId), $attackerXpGain, 'combat_attacker');
                $this->xpService->addXp(UserId::of($defenderId), $defenderXpGain, 'combat_defender');
                
                // Esetleges új statisztikák / log gyanánt egyedi output beemelése
                $logData['attacker_xp_gain'] = $attackerXpGain;
                $logData['defender_xp_gain'] = $defenderXpGain;

                // Update Stats (Wins/Losses)
                try {
                    $this->db->executeStatement("SET @audit_source = ?", ['CombatService::stats']);
                    $this->db->executeStatement("UPDATE users SET wins = wins + 1 WHERE id = ?", [$winnerId]);
                    $this->db->executeStatement("UPDATE users SET losses = losses + 1 WHERE id = ?", [$loserId]);
                } finally {
                    $this->db->executeStatement("SET @audit_source = NULL");
                }
            }

            // --- Narrative Generation & Logging ---
            
            $scenarioType = '';
            // Determine vehicle usage flags first for logic
            $hasAttVehicle = (!empty($attVehicle) && ($attVehicle['fuel_amount'] ?? 0) >= 20);
            
            if ($escaped) {
                $scenarioType = 'escape_attacker'; 
            } elseif ($winnerId == $attackerId) {
                $scenarioType = $hasAttVehicle ? 'attacker_win_vehicle' : 'attacker_win';
            } else {
                $scenarioType = $hasAttVehicle ? 'attacker_lose_vehicle' : 'attacker_lose';
            }
            
            $scenarioId = $this->narrator->getRandomScenarioId($scenarioType);
            
            // Build Battle Report JSON
            $battleReport = json_encode([
                'scenario_id' => $scenarioId,
                'attacker_weapon' => $attackerWeaponName,
                'defender_vehicle' => $defenderVehicleName,
                'attacker_vehicle' => ($attVehicle['name'] ?? null)
            ]);

            // Fill missing log data
            $logData['attacker_id'] = $attackerId;
            $logData['defender_id'] = $defenderId;
            $logData['attacker_xp_snapshot'] = $attacker['xp'];
            $logData['defender_xp_snapshot'] = $defender['xp'];
            $logData['attacker_points'] = $attackerScore;
            $logData['defender_points'] = $defenderScore;
            $logData['ammo_used_attacker'] = $attackerAmmo;
            $logData['ammo_used_defender'] = $defenderAmmo;
            
            // Determine vehicle usage flags
            $logData['vehicle_used_attacker'] = (!empty($attVehicle) && ($attVehicle['fuel_amount'] ?? 0) >= 20) ? 1 : 0;
            $logData['vehicle_used_defender'] = (!empty($defVehicle) && ($defVehicle['fuel_amount'] ?? 0) >= 20) ? 1 : 0;
            
            $logData['battle_report'] = $battleReport;

            $this->repository->logFight($logData);

            $this->db->commit();
            
            return [
                'winner_id' => $winnerId,
                'money_stolen' => $moneyStolen,
                'damage_dealt' => $damage,
                'gained_xp' => $attackerXpGain, // Visszaadjuk a UI-nak
                'escaped' => $escaped,
                'message' => $resultMessage
            ];

        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
