<?php

require __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

use Rider\Core\Auth;
use Rider\Core\Request;
use Rider\Core\Response;
use Rider\Core\Router;

// Off by default so an unconfigured environment (e.g. a fresh Vercel deploy
// with no database wired yet) never leaks internal file paths/stack traces
// to a public response; set APP_DEBUG=true locally (see .env.example) to
// see real errors while developing.
$debug = getenv('APP_DEBUG') === 'true';
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

Auth::start();

$router = new Router();
require __DIR__ . '/../routes/api.php';
if (is_file(__DIR__ . '/../routes/web.php')) {
    require __DIR__ . '/../routes/web.php';
}

try {
    $request = Request::fromGlobals();
    $request->user = Auth::currentUser();
    $router->dispatch($request);
} catch (\Throwable $e) {
    error_log((string) $e);
    if ($debug) {
        throw $e;
    }
    Response::error('Internal server error — this environment may not have a database connected yet.', 500);
}
