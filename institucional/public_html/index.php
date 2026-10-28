<?php
declare(strict_types=1);
use LuzPropria\LuzPanel\Kernel;
use Symfony\Component\HttpFoundation\Request;
if (PHP_SAPI === 'cli-server') {
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
    if (preg_match('#^/(?:vendor|src|config|var|views)(?:/|$)|(?:^|/)\.#', $path)) { http_response_code(403); exit; }
    $file = realpath(__DIR__.$path);
    if ($file && in_array($path, ['/web/phpmailer/send-contato.php', '/revista-analytics.php'], true)) { return false; }
    if ($file && str_starts_with($file, __DIR__.DIRECTORY_SEPARATOR) && is_file($file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') { return false; }
}
require __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/helpers.php';
date_default_timezone_set('America/Sao_Paulo');
$environment = getenv('APP_ENV') ?: 'prod';
$debug = filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOL);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);
$kernel = new Kernel($environment, $debug);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
