<?php
require __DIR__ . '/../vendor/autoload.php';
$container = (new \DI\ContainerBuilder())->addDefinitions(__DIR__ . '/../config/container.php')->build();
/** @var \Doctrine\DBAL\Connection $db */
$db = $container->get(\Doctrine\DBAL\Connection::class);

echo "Adatbázis javítása: credit_transactions.type bővítése...\n";

$sql = "ALTER TABLE credit_transactions MODIFY COLUMN type ENUM(
    'purchase','admin_add','admin_remove','referral','spend','refund',
    'transfer_in','transfer_out','expired','correction',
    'market_escrow_in','market_escrow_out'
)";

try {
    $db->executeStatement($sql);
    echo "✅ SIKER: Az ENUM típus frissítve!\n";
} catch (\Throwable $e) {
    echo "❌ HIBA: " . $e->getMessage() . "\n";
}
