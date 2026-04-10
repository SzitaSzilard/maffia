<?php
require __DIR__ . '/../../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();
$pdo = new PDO('mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'], $_ENV['DB_USER'], $_ENV['DB_PASS']);

$r = $pdo->query('SELECT origin_country, COUNT(*) as cnt FROM vehicles GROUP BY origin_country ORDER BY cnt DESC');
foreach ($r as $row) {
    echo $row['origin_country'] . ' => ' . $row['cnt'] . "\n";
}
