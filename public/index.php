<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Native Word conversion has a bounded 90-second timeout. Allow bootstrap and
// error handling to finish instead of PHP aborting the request after 30 seconds.
if ((int) ini_get('max_execution_time') > 0 && (int) ini_get('max_execution_time') < 120) set_time_limit(120);

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
