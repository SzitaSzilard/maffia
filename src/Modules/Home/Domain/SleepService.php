<?php
declare(strict_types=1);

namespace Netmafia\Modules\Home\Domain;

use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;

use Doctrine\DBAL\Connection;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Modules\Health\Domain\HealthService;

class SleepService
{
    private Connection $db;
    private PropertyService $propertyService;
    private HealthService $healthService;
    
    public function __construct(
        Connection $db,
        PropertyService $propertyService,
        HealthService $healthService
    ) {
        $this->db = $db;
        $this->propertyService = $propertyService;
        $this->healthService = $healthService;
    }
    
    /**
     * Lefekvés (alvás indítása)
     */
    public function startSleep(UserId $userId, int $hours, string $countryCode): void
    {
        // 1. Validáció
        if ($hours < \Netmafia\Modules\Home\HomeConfig::SLEEP_MIN_HOURS || $hours > \Netmafia\Modules\Home\HomeConfig::SLEEP_MAX_HOURS) {
            throw new InvalidInputException(sprintf('Alvás időtartama %d és %d óra között lehet!', \Netmafia\Modules\Home\HomeConfig::SLEEP_MIN_HOURS, \Netmafia\Modules\Home\HomeConfig::SLEEP_MAX_HOURS));
        }
        
        // 2. Ellenőrzés: már alszik?
        if ($this->isUserSleeping($userId)) {
            throw new GameException('Már alszol! Előbb kelj fel.');
        }
        
        // 3. Transaction
        $this->db->beginTransaction();
        
        try {
            // 4. Ingatlan ellenőrzés (regeneráció érték)
            $regen = $this->propertyService->getSleepRegenerationForCountry(
                $userId->id(),
                $countryCode
            );
            
            $isOnStreet = ($regen['health_regen_percent'] === 0 && $regen['energy_regen_percent'] === 0);
            
            // Ha utcán -> alapregeneráció
            if ($isOnStreet) {
                $regen['health_regen_percent'] = \Netmafia\Modules\Home\HomeConfig::STREET_HEALTH_REGEN;
                $regen['energy_regen_percent'] = \Netmafia\Modules\Home\HomeConfig::STREET_ENERGY_REGEN;
            }
            
            // 5. Alvás beszúrása
            $sleepStartedAt = new \DateTime();
            $sleepEndAt = (clone $sleepStartedAt)->modify("+{$hours} hours");
            
            $this->db->insert('user_sleep', [
                'user_id' => $userId->id(),
                'sleep_started_at' => $sleepStartedAt->format('Y-m-d H:i:s'),
                'sleep_duration_hours' => $hours,
                'sleep_end_at' => $sleepEndAt->format('Y-m-d H:i:s'),
                'country_code' => $countryCode,
                'is_on_street' => $isOnStreet ? 1 : 0,
                'health_regen_per_hour' => $regen['health_regen_percent'],
                'energy_regen_per_hour' => $regen['energy_regen_percent']
            ]);
            
            $this->db->commit();
            
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Felkelés (korai vagy automatikus)
     */
    public function wakeUp(UserId $userId): array
    {
        $sleep = $this->getActiveSleep($userId);
        
        if (!$sleep) {
            throw new GameException('Nem alszol!');
        }
        
        // Regeneráció számítása - DB NOW()-t használjuk!
        $dbNow = $this->db->fetchOne("SELECT NOW()");
        $now = new \DateTime($dbNow);
        $start = new \DateTime($sleep['sleep_started_at']);
        $hoursSlept = ($now->getTimestamp() - $start->getTimestamp()) / 3600;
        $hoursSlept = max(0, min($hoursSlept, $sleep['sleep_duration_hours']));
        
        // Valódi részarányos regeneráció (0.5 óra = 50% regen)
        $healthGain = (int)($hoursSlept * $sleep['health_regen_per_hour']);
        $energyGain = (int)($hoursSlept * $sleep['energy_regen_per_hour']);
        
        // Transaction
        $this->db->beginTransaction();
        
        try {
            // Health/Energy hozzáadása
            if ($healthGain > 0) {
                $this->healthService->heal($userId, $healthGain, 'sleep');
            }
            
            if ($energyGain > 0) {
                $this->healthService->restoreEnergy($userId, $energyGain, 'sleep');
            }
            
            // Alvás törlése
            $this->db->delete('user_sleep', ['id' => $sleep['id']]);
            
            $this->db->commit();
            
            return [
                'hours_slept' => $hoursSlept,
                'health_gained' => $healthGain,
                'energy_gained' => $energyGain
            ];
            
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
    
    /**
     * Alvási státusz lekérdezése
     * 
     * [2026-02-15] FIX: Side-effect eltávolítva – korábban az olvasó metódus
     * (getSleepStatus) írást végzett (wakeUp hívás), ami cache-elési és
     * párhuzamos hívási problémákat okozhatott.
     * Most egy 'should_wake_up' flag-et ad vissza, és a hívó (Action réteg)
     * dönt a tényleges felkeltésről.
     */
    public function getSleepStatus(UserId $userId): ?array
    {
        $sleep = $this->getActiveSleep($userId);
        
        if (!$sleep) {
            return null;
        }
        
        $dbNow = $this->db->fetchOne("SELECT NOW()");
        $now = new \DateTime($dbNow);
        $end = new \DateTime($sleep['sleep_end_at']);
        $start = new \DateTime($sleep['sleep_started_at']);
        
        // [2026-02-15] FIX: Flag visszaadása wakeUp() hívás helyett
        $shouldWakeUp = ($now >= $end);
        
        $remaining = max(0, $end->getTimestamp() - $now->getTimestamp());
        $elapsed = $now->getTimestamp() - $start->getTimestamp();
        
        return [
            'sleep_id' => $sleep['id'],
            'sleep_end_at' => $sleep['sleep_end_at'],
            'is_on_street' => (bool)$sleep['is_on_street'],
            'hours_total' => $sleep['sleep_duration_hours'],
            'hours_elapsed' => floor($elapsed / 3600),
            'seconds_remaining' => $remaining,
            'health_regen' => $sleep['health_regen_per_hour'],
            'energy_regen' => $sleep['energy_regen_per_hour'],
            'should_wake_up' => $shouldWakeUp // Action réteg kezelje!
        ];
    }
    
    /**
     * Alszik-e a user?
     */
    public function isUserSleeping(UserId $userId): bool
    {
        return $this->getActiveSleep($userId) !== null;
    }
    
    /**
     * Aktív alvás lekérdezése
     */
    private function getActiveSleep(UserId $userId): ?array
    {
        $result = $this->db->fetchAssociative(
            "SELECT id, user_id, sleep_started_at, sleep_duration_hours, sleep_end_at, country_code, is_on_street, health_regen_per_hour, energy_regen_per_hour FROM user_sleep WHERE user_id = ? LIMIT 1",
            [$userId->id()]
        );
        
        return $result ?: null;
    }
}
