<?php
declare(strict_types=1);

namespace Netmafia\Modules\OrganizedCrime\Domain;

use Doctrine\DBAL\Connection;
use Netmafia\Modules\Health\Domain\HealthService;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Modules\Home\Domain\SleepService;
use Netmafia\Shared\Exceptions\GameException;
use RuntimeException;

class OrganizedCrimeService
{
    private Connection $db;

    private \Netmafia\Modules\Money\Domain\MoneyService $moneyService;
    private \Netmafia\Modules\Xp\Domain\XpService $xpService;
    private HealthService $healthService;
    private \Netmafia\Infrastructure\CacheService $cacheService;
    private ?\Netmafia\Modules\Notifications\Domain\NotificationService $notifService;
    private SleepService $sleepService;

    public function __construct(
        Connection $db,
        \Netmafia\Modules\Money\Domain\MoneyService $moneyService,
        \Netmafia\Modules\Xp\Domain\XpService $xpService,
        HealthService $healthService,
        \Netmafia\Infrastructure\CacheService $cacheService,
        SleepService $sleepService,
        ?\Netmafia\Modules\Notifications\Domain\NotificationService $notifService = null
    ) {
        $this->db = $db;
        $this->moneyService = $moneyService;
        $this->xpService = $xpService;
        $this->healthService = $healthService;
        $this->cacheService = $cacheService;
        $this->sleepService = $sleepService;
        $this->notifService = $notifService;
    }

    public function getActiveCrimeForUser(int $userId): ?array
    {
        // Keresünk olyan bűnözést, amiben a user részt vesz, és még fut (gathering, in_progress)
        $sql = "
            SELECT c.id, c.leader_id, c.crime_type, c.status, c.country_code, m.status as pivot_status
            FROM organized_crimes c
            JOIN organized_crime_members m ON m.crime_id = c.id
            WHERE m.user_id = ? AND c.status IN ('gathering', 'in_progress') AND m.status != 'declined'
            LIMIT 1
        ";
        $crime = $this->db->fetchAssociative($sql, [$userId]);

        // Ha nincs, de akarunk egyet mutatni, akkor generálunk egy üres gatheringet a usernek mint szervező
        if (!$crime) {
            return null; // Don't auto-generate
        }

        // Tagok betöltése
        $membersSql = "
            SELECT m.id, m.user_id, m.crime_id, m.role, m.status, m.vehicle_id, m.vehicle_name,
                   u.username, u.xp, u.energy, u.oc_success_count, u.is_union_member, u.is_admin
            FROM organized_crime_members m
            JOIN users u ON u.id = m.user_id
            WHERE m.crime_id = ?
            ORDER BY m.id ASC
        ";
        $membersRows = $this->db->fetchAllAssociative($membersSql, [$crime['id']]);
        
        // Batch lekérdezés: gang_members és user_vehicles (N+1 elkerülése)
        $memberUserIds = array_column($membersRows, 'user_id');
        $gangLeaders = [];
        $vehicleOwners = [];
        if (!empty($memberUserIds)) {
            $placeholders = implode(',', array_fill(0, count($memberUserIds), '?'));
            $gangRows = $this->db->fetchAllAssociative(
                "SELECT user_id, is_leader FROM gang_members WHERE user_id IN ({$placeholders})",
                $memberUserIds
            );
            foreach ($gangRows as $gr) {
                $gangLeaders[(int)$gr['user_id']] = (bool)$gr['is_leader'];
            }
            $vehicleRows = $this->db->fetchAllAssociative(
                "SELECT DISTINCT user_id FROM user_vehicles WHERE user_id IN ({$placeholders})",
                $memberUserIds
            );
            foreach ($vehicleRows as $vr) {
                $vehicleOwners[(int)$vr['user_id']] = true;
            }
        }

        $members = [];
        foreach ($membersRows as $row) {
            $roleStatus = $this->evaluateMemberStatus((int)$row['user_id'], $row['role'], $row, $gangLeaders, $vehicleOwners);
            $members[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'username' => $row['username'],
                'role' => $row['role'],
                'status' => $row['status'],
                'vehicle_id' => $row['vehicle_id'] ?? null,
                'vehicle_name' => $row['vehicle_name'] ?? null,
                'chance_pct' => $roleStatus['chance_pct'],
                'missing_requirement' => $roleStatus['missing_requirement']
            ];
        }
        
        $crime['members'] = $members;
        return $crime;
    }

    public function createCrimeForUser(int $userId, string $type): void
    {
        if ($this->sleepService->isUserSleeping(UserId::of($userId))) {
            throw new GameException('Épp alszol, ezért nem tudsz tevékenykedni!');
        }

        // Whitelist: csak érvényes bűnözés típus engedélyezett (§10.1)
        if (!in_array($type, OrganizedCrimeConfig::VALID_CRIME_TYPES, true)) {
            throw new \Netmafia\Shared\Exceptions\InvalidInputException('Érvénytelen bűnözés típus!');
        }

        $countryCode = $this->db->fetchOne("SELECT country_code FROM users WHERE id = ?", [$userId]);

        $this->db->beginTransaction();
        try {
            $this->db->insert('organized_crimes', [
                'leader_id' => $userId,
                'crime_type' => $type,
                'status' => 'gathering',
                'country_code' => $countryCode ?: 'HU'
            ]);
            
            $crimeId = (int)$this->db->lastInsertId();
            
            $this->db->insert('organized_crime_members', [
                'crime_id' => $crimeId,
                'user_id' => $userId,
                'role' => 'organizer',
                'status' => 'accepted'
            ]);
            
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw new RuntimeException("Nem sikerült létrehozni a bűnözést: " . $e->getMessage());
        }
    }

    private function evaluateMemberStatus(int $userId, string $role, array $memberDbRow, array $gangLeaders = [], array $vehicleOwners = []): array
    {
        $missing = null;
        $chance = 0;
        
        if ($memberDbRow['status'] === 'accepted' || $role === 'organizer') {
            
            if ((int)$memberDbRow['energy'] < OrganizedCrimeConfig::MIN_ENERGY_REQUIRED) {
                $missing = "Nincs " . OrganizedCrimeConfig::MIN_ENERGY_REQUIRED . "% energiája";
            }
            
            if ($role === 'gang_leader') {
                if (empty($gangLeaders[$userId])) {
                    $missing = "Nem bandafőnök";
                }
            }
            
            if (in_array($role, OrganizedCrimeConfig::VEHICLE_ROLES, true)) {
                if (empty($memberDbRow['vehicle_id'])) {
                    $missing = empty($vehicleOwners[$userId]) ? "Nincs a garázsban járműve" : "Még nem választott autót";
                }
            }
            
            $xp = (int)($memberDbRow['xp'] ?? 0);
            $rankInfo = \Netmafia\Shared\Domain\RankCalculator::getRankInfo($xp);
            $level = $rankInfo['index'] + 1;
            $experience = (int)($memberDbRow['oc_success_count'] ?? 0);
            
            if (empty($memberDbRow['is_admin']) && $rankInfo['index'] < OrganizedCrimeConfig::MIN_RANK_INDEX) {
                $missing = "Nincs Katona rangja (2500 XP)";
            }

            if ($role === 'union_member' && empty($memberDbRow['is_union_member'])) {
                $missing = "Nem szakszervezeti tag";
            }
            
            $baseChance = OrganizedCrimeConfig::BASE_CHANCE_PCT;
            $levelBonus = min(OrganizedCrimeConfig::MAX_LEVEL_BONUS_PCT, $level * OrganizedCrimeConfig::LEVEL_BONUS_MULTIPLIER);
            $expBonus = min(OrganizedCrimeConfig::MAX_EXPERIENCE_BONUS_PCT, $experience * OrganizedCrimeConfig::EXPERIENCE_BONUS_MULTIPLIER);
            $rolePenalty = OrganizedCrimeConfig::ROLE_PENALTIES[$role] ?? 5.0;
            
            $calculatedChance = $baseChance + $levelBonus + $expBonus - $rolePenalty;
            $chance = (int)max(OrganizedCrimeConfig::MIN_CHANCE_PCT, min(OrganizedCrimeConfig::MAX_CHANCE_PCT, $calculatedChance));
        }

        return [
            'chance_pct' => $chance,
            'missing_requirement' => $missing
        ];
    }

    public function inviteMember(int $crimeId, int $inviterId, int $targetUserId, string $role): array
    {
        // Whitelist: organizer szerepet nem lehet meghívással átadni (§10.1, §3.7)
        if (!in_array($role, OrganizedCrimeConfig::VALID_ROLES, true) || $role === 'organizer') {
            return ['success' => false, 'error' => 'Érvénytelen szerepkör!'];
        }

        $this->db->beginTransaction();
        try {
            // IDOR védelem: csak a szervező hívhat meg, crime-ra FOR UPDATE lock (§3.4, §3.8)
            $isOrg = $this->db->fetchOne(
                "SELECT 1 FROM organized_crimes WHERE id = ? AND leader_id = ? AND status = 'gathering' FOR UPDATE",
                [$crimeId, $inviterId]
            );
            if (!$isOrg) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Nem te vagy a szervező, vagy a bűnözés már nem gyűjtési fázisban van!'];
            }

            // Race condition védelem: members sorra FOR UPDATE, hogy párhuzamos meghívás ne duplázzon (§2.6)
            $existing = $this->db->fetchOne(
                "SELECT id FROM organized_crime_members WHERE crime_id = ? AND role = ? FOR UPDATE",
                [$crimeId, $role]
            );
            if ($existing) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Ez a poszt már be van töltve vagy valaki meg van hívva rá!'];
            }

            $this->db->insert('organized_crime_members', [
                'crime_id' => $crimeId,
                'user_id' => $targetUserId,
                'role' => $role,
                'status' => 'invited'
            ]);

            $this->db->commit();
            $this->cacheService->forget("pending_oc_invites:{$targetUserId}");
            return ['success' => true];
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => 'Adatbázis hiba a meghívás mentésekor!'];
        }
    }

    public function acceptInvite(int $userId, int $crimeId): void
    {
        $this->db->update('organized_crime_members', ['status' => 'accepted'], ['user_id' => $userId, 'crime_id' => $crimeId, 'status' => 'invited']);
        $this->cacheService->forget("pending_oc_invites:{$userId}");
    }

    public function declineInvite(int $userId, int $crimeId): void
    {
        $this->db->delete('organized_crime_members', ['user_id' => $userId, 'crime_id' => $crimeId, 'status' => 'invited']);
        $this->cacheService->forget("pending_oc_invites:{$userId}");
    }

    public function revokeInvite(int $organizerId, int $crimeId, int $memberId): array
    {
        // Check if caller is organizer
        $isOrg = $this->db->fetchOne("SELECT 1 FROM organized_crimes WHERE id = ? AND leader_id = ?", [$crimeId, $organizerId]);
        if (!$isOrg) {
            return ['success' => false, 'error' => 'Nem te vagy a szervező!'];
        }

        $memberData = $this->db->fetchAssociative("SELECT user_id, status FROM organized_crime_members WHERE id = ?", [$memberId]);
        if (!$memberData) {
            return ['success' => false, 'error' => 'Nem található ilyen tag!'];
        }

        $userIdToRevoke = $memberData['user_id'];
        $status = $memberData['status'];

        $this->db->delete('organized_crime_members', ['id' => $memberId, 'crime_id' => $crimeId]);
        
        if ($userIdToRevoke) {
            $this->cacheService->forget("pending_oc_invites:{$userIdToRevoke}");
        }
        
        return ['success' => true, 'user_id' => $userIdToRevoke, 'status' => $status];
    }

    public function leaveCrime(int $userId, int $crimeId): void
    {
        $this->db->delete('organized_crime_members', ['user_id' => $userId, 'crime_id' => $crimeId]);
        $this->cacheService->forget("pending_oc_invites:{$userId}");
    }

    public function disbandCrime(int $organizerId, int $crimeId): array
    {
        $isOrg = $this->db->fetchOne("SELECT 1 FROM organized_crimes WHERE id = ? AND leader_id = ?", [$crimeId, $organizerId]);
        if (!$isOrg) {
            return ['success' => false, 'error' => 'Nem te vagy a szervező!'];
        }

        // Először lekérjük a tagokat, akik már elfogadták, hogy értesítést kapjanak
        $members = $this->db->fetchAllAssociative("SELECT user_id FROM organized_crime_members WHERE crime_id = ? AND status = 'accepted' AND role != 'organizer'", [$crimeId]);
        
        $invitedMembers = $this->db->fetchAllAssociative("SELECT user_id FROM organized_crime_members WHERE crime_id = ? AND status = 'invited'", [$crimeId]);
        
        $this->db->beginTransaction();
        try {
            $this->db->update('organized_crimes', ['status' => 'cancelled'], ['id' => $crimeId]);
            $this->db->commit();
            
            foreach ($invitedMembers as $im) {
                $this->cacheService->forget("pending_oc_invites:{$im['user_id']}");
            }
            
            return ['success' => true, 'members' => array_column($members, 'user_id')];
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => 'Nem sikerült feloszlatni a bűnözést!'];
        }
    }

    public function getAvailableVehiclesForCrime(int $userId): array
    {
        // 1. Megkeressük, hogy a user melyik gathering KSZB-ben van benne (ahol majd kell kocsi)
        $crime = $this->getActiveCrimeForUser($userId);
        if (!$crime || $crime['status'] !== 'gathering') {
            return []; // Nem elérhető, vagy már fut
        }

        // 2. Megnézzük a tagok között a saját role-ját (hogy tényleg driver_1 vagy driver_2-e)
        // És azt is, hogy MILYEN ORSZÁGBAN van épp a bűnözés? Ezt betöltjük a bűnözés rekordjából.
        $countryCode = $crime['country_code'];

        if (!$countryCode) {
            return [];
        }

        $qb = $this->db->createQueryBuilder();
        $qb->select('uv.*', 'uv.fuel_amount AS current_fuel',
                    'v.name AS type', 'v.speed AS base_speed', 'v.safety AS base_safety', 'v.category')
           ->from('user_vehicles', 'uv')
           ->join('uv', 'vehicles', 'v', 'uv.vehicle_id = v.id')
           ->where('uv.user_id = :userId')
           ->andWhere('uv.country = :country')
           ->setParameter('userId', $userId)
           ->setParameter('country', $countryCode);

        // Fetch all matching vehicles in country with fuel
        $vehicles = $qb->executeQuery()->fetchAllAssociative();

        foreach ($vehicles as &$vehicle) {
            $baseSpeed = (int)$vehicle['base_speed'];
            $baseSafety = (int)$vehicle['base_safety'];

            $speedMultiplier = 1.0;
            $safetyMultiplier = 1.0;

            $speedMultiplier += ((int)($vehicle['tuning_engine'] ?? 0) * 0.05);
            $speedMultiplier += ((int)($vehicle['tuning_exhaust'] ?? 0) * 0.05);
            $speedMultiplier += ((int)($vehicle['tuning_nitros'] ?? 0) * 0.05);

            $safetyMultiplier += ((int)($vehicle['tuning_tires'] ?? 0) * 0.05);
            $safetyMultiplier += ((int)($vehicle['tuning_brakes'] ?? 0) * 0.05);
            $safetyMultiplier += ((int)($vehicle['tuning_body'] ?? 0) * 0.05);

            $mixedLevel = (int)($vehicle['tuning_shocks'] ?? 0) + (int)($vehicle['tuning_wheels'] ?? 0);
            $speedMultiplier += ($mixedLevel * 0.02);
            $safetyMultiplier += ($mixedLevel * 0.02);

            if (!empty($vehicle['has_bulletproof_glass'])) $safetyMultiplier += 0.02;
            if (!empty($vehicle['has_steel_body'])) $safetyMultiplier += 0.02;
            if (!empty($vehicle['has_runflat_tires'])) $safetyMultiplier += 0.02;
            if (!empty($vehicle['has_explosion_proof_tank'])) $safetyMultiplier += 0.02;

            $vehicle['speed'] = (int)($baseSpeed * $speedMultiplier);
            $vehicle['safety'] = (int)($baseSafety * $safetyMultiplier);
        }
        unset($vehicle);

        // Sort by effective speed DESC
        usort($vehicles, function($a, $b) {
            return $b['speed'] <=> $a['speed'];
        });

        // Limit to 3 (top fastest)
        return array_slice($vehicles, 0, 3);
    }

    public function selectVehicle(int $userId, int $userVehicleId): array
    {
        if ($this->sleepService->isUserSleeping(UserId::of($userId))) {
            return ['success' => false, 'error' => 'Épp alszol, ezért nem tudsz tevékenykedni!'];
        }

        $this->db->beginTransaction();
        try {
            // 1. Lock a bűnözés tagra vonatkozó sornál FOR UPDATE védelemmel
            $member = $this->db->fetchAssociative("
                SELECT m.id, m.crime_id, m.role, m.status, m.vehicle_id, c.leader_id, c.status as crime_status, c.country_code
                FROM organized_crime_members m
                JOIN organized_crimes c ON c.id = m.crime_id
                WHERE m.user_id = ? AND c.status = 'gathering'
                FOR UPDATE
            ", [$userId]);

            if (!$member) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Nincs aktív szervezés, amihez járművet választhatnál!'];
            }

            if (!in_array($member['role'], ['driver_1', 'driver_2'], true)) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Csak az autósofőr szerepkör számára lehet járművet választani!'];
            }

            if ($member['status'] !== 'accepted') {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Előbb el kell fogadnod a meghívást!'];
            }

            if (!empty($member['vehicle_id'])) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Már választottál járművet ehhez az akcióhoz!'];
            }

            // 2. Lock a jármű ellenőrzésére: a useré-e, az adott országban van-e, és elég a benzin?
            $countryCode = $member['country_code'];

            $vehicle = $this->db->fetchAssociative("
                SELECT uv.id, v.name 
                FROM user_vehicles uv
                JOIN vehicles v ON v.id = uv.vehicle_id
                WHERE uv.id = ? AND uv.user_id = ? AND uv.country = ? AND uv.fuel_amount >= 40
            ", [$userVehicleId, $userId, $countryCode]);

            if (!$vehicle) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Érvénytelen jármű: nem a tied, nincs abban az országban, vagy nincs benne elég (40L) benzin!'];
            }

            // 3. Mentés a pivot táblába
            $this->db->update('organized_crime_members', [
                'vehicle_id' => $userVehicleId,
                'vehicle_name' => $vehicle['name']
            ], [
                'id' => $member['id']
            ]);

            $this->db->commit();
            return ['success' => true];
            
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => 'Kritikus hiba a jármű választásakor: ' . $e->getMessage()];
        }
    }

    public function startCrime(int $userId, int $crimeId): array
    {
        if ($this->sleepService->isUserSleeping(UserId::of($userId))) {
            return ['success' => false, 'error' => 'Épp alszol, ezért nem tudsz tevékenykedni!'];
        }

        $this->db->beginTransaction();
        try {
            $crime = $this->db->fetchAssociative("SELECT id, leader_id, crime_type, status FROM organized_crimes WHERE id = ? AND leader_id = ? AND status IN ('gathering', 'in_progress') FOR UPDATE", [$crimeId, $userId]);
            if (!$crime) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Érvénytelen bűnözés vagy nem te vagy a szervező!'];
            }

        $members = $this->db->fetchAllAssociative("
            SELECT m.id, m.user_id, m.crime_id, m.role, m.status, m.vehicle_id, m.vehicle_name,
                   u.username, u.xp, u.energy, u.oc_success_count, u.money, u.is_union_member, u.is_admin
            FROM organized_crime_members m
            JOIN users u ON u.id = m.user_id
            WHERE m.crime_id = ?
        ", [$crimeId]);

        $acceptedCount = 0;
        foreach ($members as $m) {
            if ($m['status'] === 'accepted' || $m['role'] === 'organizer') $acceptedCount++;
        }
        if ($acceptedCount !== OrganizedCrimeConfig::REQUIRED_MEMBER_COUNT) {
            $this->db->rollBack();
            return ['success' => false, 'error' => "Nincs meg a " . OrganizedCrimeConfig::REQUIRED_MEMBER_COUNT . " elfogadott tag! Jelenleg: {$acceptedCount}/" . OrganizedCrimeConfig::REQUIRED_MEMBER_COUNT . "."];
        }

        // Batch lekérdezés gang + vehicle adatokhoz (N+1 elkerülése)
        $memberUserIds = array_column($members, 'user_id');
        $gangLeaders = [];
        $vehicleOwners = [];
        if (!empty($memberUserIds)) {
            $placeholders = implode(',', array_fill(0, count($memberUserIds), '?'));
            $gangRows = $this->db->fetchAllAssociative(
                "SELECT user_id, is_leader FROM gang_members WHERE user_id IN ({$placeholders})",
                $memberUserIds
            );
            foreach ($gangRows as $gr) {
                $gangLeaders[(int)$gr['user_id']] = (bool)$gr['is_leader'];
            }
            $vehicleRows = $this->db->fetchAllAssociative(
                "SELECT DISTINCT user_id FROM user_vehicles WHERE user_id IN ({$placeholders})",
                $memberUserIds
            );
            foreach ($vehicleRows as $vr) {
                $vehicleOwners[(int)$vr['user_id']] = true;
            }
        }

        $totalChance = 0;
        $rolesDict = [];
        
        foreach ($members as $m) {
            if ($m['status'] !== 'accepted' && $m['role'] !== 'organizer') continue;
            
            $status = $this->evaluateMemberStatus((int)$m['user_id'], $m['role'], $m, $gangLeaders, $vehicleOwners);
            if ($status['missing_requirement']) {
                $this->db->rollBack();
                return ['success' => false, 'error' => "A(z) {$m['username']} felhasználónak hiányzik egy követelménye: {$status['missing_requirement']}"];
            }
            $totalChance += $status['chance_pct'];
            $rolesDict["{{$m['role']}}"] = $m['username'];
        }

        $avgChance = $totalChance / OrganizedCrimeConfig::REQUIRED_MEMBER_COUNT;
        $roll = random_int(1, 100);
        $isSuccess = ($roll <= $avgChance);
        
        $narrativesSuccess = [
            "A kaszinó kirablása mesterien sikerült! {organizer} remekül irányított, míg {gang_leader} keményen tartotta a frontot. {hacker} egy perc alatt blokkolta a kamerákat. A {driver_1} és {driver_2} gumicsikorgatva menekítettek ki titeket a pénzzel telepakolt táskákkal.",
            "Csendes és profi rablás volt. {union_member} zseniálisan juttatott be titeket a páncélterembe, miközben {organizer} a háttérből figyelt. {pilot} már melegítette a gép motorját, amikor {gunman_1} és {gunman_2} biztosították a kijáratot.",
            "Mintaszerű akció! Bár {organizer} izzadt rendesen, {hacker} a másodperc törtrésze alatt felnyitotta az elektronikus zárakat. A végén {driver_1} majdnem nekiment egy oszlopnak, de sikeresen eltűntetek a helyszínről.",
            "Kisebb lövöldözés a kaszinó halljában: {gunman_1}, {gunman_2} és {gunman_3} zárótüzet biztosított, miközben {gang_leader} vezetésével feltörtétek a VIP trezort. {pilot} fantasztikus landolással vett fel titeket a tetőn.",
            "Ocean's Eleven ehhez képest amatőr brigád! {union_member} segítségével észrevétlenül jutottatok ki a pincéből. A {driver_2} által vezetett kisbuszban {organizer} már számolhatta is a milliós készpénzt."
        ];
        
        $narrativesFail = [
            "Teljes csődtömeg. {organizer} elszámította az időt, {hacker} pedig leblokkolt a firewall láttán. A zsaruk azonnal körbevettek titeket, és {driver_1} hiába várta a mentőcsapatot.",
            "A kaszinó biztonsági főnöke eszén nem tudtatok túljárni. {gunman_3} korán lőtt, és kitört a pánik. Még szerencse, hogy nem dugtak mindenkit hosszú évekre a rácsok mögé, de az akció elbukott.",
            "{pilot} lekéste a találkozási pontot a tetőn, így a csapat csapdába esett. {gang_leader} és {gunman_1} próbáltak utat törni, de túlerőben voltak a rendőrök. Buktátok a pénzt és be is sérültetek.",
            "Amatőr hiba! {union_member} hamis tervrajzot szerzett. Csupa üres széfet találtatok, a riasztó pedig már az első perctől visított. {driver_2} gázt adott és elmenekült nélkületek.",
            "Minden rosszul sült el. {organizer} terve besült, a SWAT alakulat rajtaütött a csapaton még a trezor előtt. {gunman_2} megsebesült, a pénzt pedig egyből lefoglalták."
        ];

        $narrativeList = $isSuccess ? $narrativesSuccess : $narrativesFail;
        $narrativeTemplate = $narrativeList[array_keys($narrativeList)[random_int(0, max(0, count($narrativeList) - 1))]];
        $narrativeFinal = str_replace(array_keys($rolesDict), array_values($rolesDict), $narrativeTemplate);

        $totalReward = $isSuccess ? random_int(OrganizedCrimeConfig::REWARD_MIN, OrganizedCrimeConfig::REWARD_MAX) : 0;
        $roleShares = OrganizedCrimeConfig::ROLE_SHARES;

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $cooldownUntil = $now->modify('+' . OrganizedCrimeConfig::COOLDOWN_SECONDS . ' seconds')->format('Y-m-d H:i:s');

        $this->db->update('organized_crimes', ['status' => $isSuccess ? 'success' : 'failed'], ['id' => $crimeId]);

        // Set audit source once for the whole loop of updates
        $this->db->executeStatement("SET @audit_source = ?", ['OrganizedCrimeService::startCrime']);

            try {
                foreach ($members as $m) {
                    if ($m['status'] !== 'accepted' && $m['role'] !== 'organizer') continue;
                    
                    $mUserId = (int)$m['user_id'];
                    $userIdObj = UserId::of($mUserId);
                    $mRole = $m['role'];
                    
                    $this->db->executeStatement("UPDATE users SET oc_cooldown_until = ? WHERE id = ?", [$cooldownUntil, $mUserId]);
    
                    if ($isSuccess) {
                        $this->db->executeStatement("UPDATE users SET oc_success_count = oc_success_count + 1 WHERE id = ?", [$mUserId]);
                        
                        $share = (int)($totalReward * $roleShares[$mRole]);
                        $this->moneyService->addMoney($userIdObj, $share, 'casino_win', 'Szervezett bűnözés: Kaszinó kirablás');
                        
                        $xpGained = random_int(OrganizedCrimeConfig::XP_GAIN_MIN, OrganizedCrimeConfig::XP_GAIN_MAX);
                        $this->xpService->addXp($userIdObj, $xpGained, 'organized_crime');
                        
                        $energyGain = random_int(OrganizedCrimeConfig::ENERGY_GAIN_MIN, OrganizedCrimeConfig::ENERGY_GAIN_MAX);
                        if ($energyGain < 0) {
                            $this->healthService->useEnergy($userIdObj, abs($energyGain), 'organized_crime');
                        } else {
                            $this->healthService->restoreEnergy($userIdObj, $energyGain, 'organized_crime');
                        }
    
                        $formattedTotal = number_format($totalReward, 0, ',', ' ');
                        $formattedShare = number_format($share, 0, ',', ' ');
                        $msg = "{$narrativeFinal} Szerzett pénz: \${$formattedTotal}, ebből te \${$formattedShare}-t kaptál.";
                        if ($this->notifService) $this->notifService->send($mUserId, 'organized_crime_result', $msg, 'organized_crime');
    
                    } else {
                        $this->db->executeStatement("UPDATE users SET oc_fail_count = oc_fail_count + 1 WHERE id = ?", [$mUserId]);
                        
                        $energyLoss = random_int(OrganizedCrimeConfig::ENERGY_LOSS_MIN, OrganizedCrimeConfig::ENERGY_LOSS_MAX);
                        $this->healthService->useEnergy($userIdObj, $energyLoss, 'organized_crime_fail');
                        $this->healthService->damage($userIdObj, OrganizedCrimeConfig::HP_LOSS_ON_FAIL, 'organized_crime_fail');
                        
                        $msg = "{$narrativeFinal} Az akció sajnos elbukott.";
                        if ($this->notifService) $this->notifService->send($mUserId, 'organized_crime_result', $msg, 'organized_crime');
                    }
                }
            } finally {
                $this->db->executeStatement("SET @audit_source = NULL");
            }
            
            $this->db->commit();
            return ['success' => true, 'is_heist_success' => $isSuccess];
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            return ['success' => false, 'error' => 'Kritikus hiba a rablás végrehajtásakor: ' . $e->getMessage()];
        }
    }
}
