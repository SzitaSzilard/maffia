<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

use Doctrine\DBAL\DriverManager;

$conn = DriverManager::getConnection([
    'dbname' => $_ENV['DB_NAME'],
    'user' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'host' => $_ENV['DB_HOST'],
    'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
]);

// Cleanup existing test users
$conn->executeStatement("DELETE FROM users WHERE username IN ('test_attacker', 'test_victim')");

$passwordHash = password_hash('password', PASSWORD_BCRYPT);

$users = [
    [
        'username' => 'test_attacker',
        'country_code' => 'HU',
        'email' => 'attacker@netmafia.test',
        'password' => $passwordHash,
        'money' => 1000000,
        'credits' => 1000,
        'bullets' => 1000,
        'health' => 100,
        'energy' => 100,
        'xp' => 1000,
        'has_laptop' => 0,
        'petty_crime_attempts' => 0,
        'car_theft_attempts' => 0,
    ],
    [
        'username' => 'test_victim',
        'country_code' => 'HU',
        'email' => 'victim@netmafia.test',
        'password' => $passwordHash,
        'money' => 1000000,
        'credits' => 1000,
        'bullets' => 1000,
        'health' => 100,
        'energy' => 100,
        'xp' => 1000,
        'has_laptop' => 0,
        'petty_crime_attempts' => 0,
        'car_theft_attempts' => 0,
    ]
];

foreach ($users as $user) {
    try {
        $conn->insert('users', $user);
        echo "Created user: {$user['username']}\n";
    } catch (\Exception $e) {
        echo "Error creating user {$user['username']}: " . $e->getMessage() . "\n";
    }
}
