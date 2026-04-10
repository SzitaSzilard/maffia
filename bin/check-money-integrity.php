<?php
/**
 * Money Integrity Check - Cron Job
 * 
 * Ellenőrzi, hogy a userek pénz egyenlege megegyezik-e a tranzakciók alapján várt értékkel.
 * 
 * Használat:
 *   php bin/check-money-integrity.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Netmafia\Modules\Money\Domain\MoneyService;

// Időzóna
date_default_timezone_set('Europe/Budapest');

echo "[" . date('Y-m-d H:i:s') . "] Pénz integritás ellenőrzés indítása...\n";

// Container építése
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

// MoneyService lekérése
$moneyService = $container->get(MoneyService::class);

// Ellenőrzés futtatása
$result = $moneyService->verifyAllUsersIntegrity();

// Eredmény kiírása
echo "Ellenőrzött userek: {$result['checked']}\n";
echo "Érvényes: {$result['valid']}\n";
echo "HIBÁS: {$result['invalid']}\n";

if ($result['invalid'] > 0) {
    echo "\n⚠️  INTEGRITÁSI HIBÁK TALÁLVA:\n";
    foreach ($result['violations'] as $v) {
        echo "  - User #{$v['user_id']} ({$v['username']}): ";
        echo "várt={$v['expected']} Ft, aktuális={$v['actual']} Ft, eltérés={$v['difference']} Ft\n";
    }
    echo "\nRészletek: SELECT * FROM money_integrity_violations WHERE resolved = FALSE;\n";
    exit(1);
}

echo "\n✅ Minden rendben, nincs integritási hiba.\n";
exit(0);
