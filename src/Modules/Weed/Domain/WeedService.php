<?php
declare(strict_types=1);

namespace Netmafia\Modules\Weed\Domain;

use Netmafia\Modules\Item\Domain\InventoryService;
use Netmafia\Modules\Weed\WeedConfig;
use Netmafia\Shared\Domain\ValueObjects\UserId;
use Netmafia\Shared\Exceptions\GameException;
use Netmafia\Shared\Exceptions\InvalidInputException;
use Doctrine\DBAL\Connection;

class WeedService
{
    private Connection $db;
    private InventoryService $inventoryService;

    public function __construct(Connection $db, InventoryService $inventoryService)
    {
        $this->db = $db;
        $this->inventoryService = $inventoryService;
    }

    /**
     * Ültetés: Vadkender magok elültetése az aktuális országban.
     * 12 órás cooldown, 45-ös hard limit országonként.
     */
    public function plant(UserId $userId, int $amount, string $countryCode): void
    {
        if ($amount < 1 || $amount > WeedConfig::MAX_PLANTS_PER_COUNTRY) {
            throw new InvalidInputException('Érvénytelen mennyiség!');
        }

        $this->db->beginTransaction();
        try {
            // 12 órás cooldown ellenőrzés (FOR UPDATE lock)
            $userData = $this->db->fetchAssociative(
                "SELECT last_weed_plant_at FROM users WHERE id = ? FOR UPDATE",
                [$userId->id()]
            );

            if (!empty($userData['last_weed_plant_at'])) {
                $remaining = strtotime($userData['last_weed_plant_at'])
                    + (WeedConfig::PLANT_COOLDOWN_HOURS * 3600)
                    - time();

                if ($remaining > 0) {
                    $hours   = (int) floor($remaining / 3600);
                    $minutes = (int) floor(($remaining % 3600) / 60);
                    throw new GameException(
                        "Az utolsó ültetés óta nem telt el {$hours} óra! Várnod kell még {$hours} óra {$minutes} percet."
                    );
                }
            }

            // Kapacitás ellenőrzés (race condition ellen — FOR UPDATE)
            $currentAmount = (int) $this->db->fetchOne(
                "SELECT COALESCE(amount, 0) FROM user_weed_plantations WHERE user_id = ? AND country_code = ? FOR UPDATE",
                [$userId->id(), $countryCode]
            );

            if ($currentAmount + $amount > WeedConfig::MAX_PLANTS_PER_COUNTRY) {
                $remaining = WeedConfig::MAX_PLANTS_PER_COUNTRY - $currentAmount;
                throw new GameException(
                    "Ebben az országban már {$currentAmount} ültetvényed van! Legfeljebb {$remaining} magot ülthetsz még."
                );
            }

            // Magok levonása inventory-ból
            $this->inventoryService->removeItem($userId->id(), WeedConfig::ITEM_WEED_SEED, $amount);

            // Ültetvény regisztrálása / frissítése
            $exists = $this->db->fetchOne(
                "SELECT 1 FROM user_weed_plantations WHERE user_id = ? AND country_code = ?",
                [$userId->id(), $countryCode]
            );

            if ($exists) {
                $this->db->executeStatement(
                    "UPDATE user_weed_plantations SET amount = amount + ? WHERE user_id = ? AND country_code = ?",
                    [$amount, $userId->id(), $countryCode]
                );
            } else {
                $this->db->insert('user_weed_plantations', [
                    'user_id'      => $userId->id(),
                    'country_code' => $countryCode,
                    'amount'       => $amount,
                ]);
            }

            // Ültetési cooldown regisztrálása + betakarítási cooldown (növekedési idő) indítása
            $this->db->executeStatement(
                "UPDATE users SET last_weed_plant_at = UTC_TIMESTAMP(), last_weed_harvest_at = UTC_TIMESTAMP() WHERE id = ?",
                [$userId->id()]
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Betakarítás: Az aktuális ország ültetvényeinek learatása.
     * 24 órás cooldown, szálankénti RNG loot generálás.
     * Visszatér a szerzett tárgyak listájával.
     */
    public function harvest(UserId $userId, string $countryCode): array
    {
        $this->db->beginTransaction();
        try {
            // 24 órás cooldown ellenőrzés (FOR UPDATE lock)
            $userData = $this->db->fetchAssociative(
                "SELECT last_weed_harvest_at FROM users WHERE id = ? FOR UPDATE",
                [$userId->id()]
            );

            if (!empty($userData['last_weed_harvest_at'])) {
                $remaining = strtotime($userData['last_weed_harvest_at'])
                    + (WeedConfig::HARVEST_COOLDOWN_HOURS * 3600)
                    - time();

                if ($remaining > 0) {
                    $hours   = (int) floor($remaining / 3600);
                    $minutes = (int) floor(($remaining % 3600) / 60);
                    throw new GameException(
                        "A következő betakarításig várnod kell {$hours} óra {$minutes} percet."
                    );
                }
            }

            // Ültetvény mennyiség lekérése (FOR UPDATE)
            $amount = (int) $this->db->fetchOne(
                "SELECT COALESCE(amount, 0) FROM user_weed_plantations WHERE user_id = ? AND country_code = ? FOR UPDATE",
                [$userId->id(), $countryCode]
            );

            if ($amount <= 0) {
                throw new GameException('Nincs betakarítható ültetvényed ebben az országban!');
            }

            $baseQuality = WeedConfig::getCountryBaseQuality($countryCode);

            // Összesített loot (nem szálankénti)
            // Mag: 1-3 db összesen
            $totalSeeds = random_int(1, 3);

            // Cigi: 1-7 db összesen
            $totalCigs = random_int(1, 7);

            // Minőség: country base + random + plant count bónusz (több növény = jobb esély)
            $plantBonus = min($amount * 2, 40); // max +40 bónusz 20+ növénytől
            $score  = $baseQuality + $plantBonus + random_int(1, 100);
            $itemId = WeedConfig::determineQualityItemId($score);

            $itemsLooted = [$itemId => $totalCigs];

            // Inventory kiosztás
            if ($totalSeeds > 0) {
                $this->inventoryService->addItem($userId->id(), WeedConfig::ITEM_WEED_SEED, $totalSeeds);
            }
            foreach ($itemsLooted as $itemId => $qty) {
                $this->inventoryService->addItem($userId->id(), $itemId, $qty);
            }

            // Ültetvény törlése az aktuális országban
            $this->db->executeStatement(
                "UPDATE user_weed_plantations SET amount = 0 WHERE user_id = ? AND country_code = ?",
                [$userId->id(), $countryCode]
            );

            // Betakarítási cooldown regisztrálása
            $this->db->executeStatement(
                "UPDATE users SET last_weed_harvest_at = UTC_TIMESTAMP() WHERE id = ?",
                [$userId->id()]
            );

            $this->db->commit();

            return [
                'seeds'       => $totalSeeds,
                'items'       => $itemsLooted,
                'plant_count' => $amount,
            ];
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
