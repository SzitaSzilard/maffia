<?php
/**
 * Credit Integrity Check - Cron Job
 * 
 * Ellenőrzi, hogy a userek credit egyenlege megegyezik-e a tranzakciók alapján várt értékkel.
 * Ha eltérést talál, logolja a credit_integrity_violations táblába.
 * 
 * Használat:
 *   php bin/check-credit-integrity.php
 * 
 * Cron:
 *   0 * * * * php /path/to/netmafia/bin/check-credit-integrity.php >> /var/log/credit-check.log 2>&1
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Netmafia\Modules\Credits\Domain\CreditService;

// Időzóna
date_default_timezone_set('Europe/Budapest');

echo "[" . date('Y-m-d H:i:s') . "] Credit integritás ellenőrzés indítása...\n";

// Container építése
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

// CreditService lekérése
$creditService = $container->get(CreditService::class);

// Ellenőrzés futtatása
$result = $creditService->verifyAllUsersIntegrity();

// Eredmény kiírása
echo "Ellenőrzött userek: {$result['checked']}\n";
echo "Érvényes: {$result['valid']}\n";
echo "HIBÁS: {$result['invalid']}\n";

if ($result['invalid'] > 0) {
    echo "\n⚠️  INTEGRITÁSI HIBÁK TALÁLVA:\n";
    foreach ($result['violations'] as $v) {
        echo "  - User #{$v['user_id']} ({$v['username']}): ";
        echo "várt={$v['expected']}, aktuális={$v['actual']}, eltérés={$v['difference']}\n";
    }
    echo "\nRészletek: SELECT * FROM credit_integrity_violations WHERE resolved = FALSE;\n";
    exit(1); // Non-zero exit code hibára
}

echo "\n✅ Minden rendben, nincs integritási hiba.\n";
exit(0);
