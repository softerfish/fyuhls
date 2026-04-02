<?php
// Define the absolute project root path
define('BASE_PATH', realpath(__DIR__ . '/..'));

require_once BASE_PATH . '/vendor/autoload.php';

use App\Core\App;

$app = new App();

// Expose $app to routes
global $router;
$router = $app->getRouter();

$app->run();
