<?php

/**
 * Server-rendered page routes (see src/Views/ and src/Core/View.php) —
 * distinct from routes/api.php's JSON API. Covers the primary customer
 * journey (request → track → complete) plus the SOS safety surface per
 * planning/02-rider-co-ke/user-flows.md §1 and §3; dispatcher/admin
 * consoles are not built here. Mirrors laundry.co.ke's routes/web.php
 * pattern (see planning/00-portfolio/ui-implementation-plan.md).
 *
 * @var \Rider\Core\Router $router
 */

use Rider\Controllers\AuthController;
use Rider\Controllers\PageController;

$page = new PageController();
$auth = new AuthController();

$router->get('/', [$page, 'home']);
$router->get('/request', [$page, 'requestForm']);
$router->get('/trips/{id}', [$page, 'tripStatus']);
$router->get('/trips/{id}/sos', [$page, 'sos']);
// /riders/onboard must be registered before the /riders/{id} wildcard
// below, since Router::dispatch matches routes in registration order and
// {id} would otherwise swallow the literal "onboard" segment.
$router->get('/riders/onboard', [$page, 'riderOnboard']);
$router->get('/riders/dashboard', [$page, 'riderDashboard']);
$router->get('/riders/{id}', [$page, 'riderProfile']);

$router->get('/signup', [$auth, 'showSignup']);
$router->post('/signup', [$auth, 'register']);
$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);
