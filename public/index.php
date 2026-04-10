<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Időzóna beállítás (CET/CEST)
date_default_timezone_set('Europe/Budapest');

// 1. Init Container
$containerBuilder = new ContainerBuilder();

// 2. Add Definitions
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');

// 3. Build Container
$container = $containerBuilder->build();

// 4. Create App
AppFactory::setContainer($container);
$app = AppFactory::create();

// 5. Register Middleware
(require __DIR__ . '/../config/middleware.php')($app);

// 6. Register Routes
(require __DIR__ . '/../config/routes.php')($app);

// 7. Run
$app->run();
