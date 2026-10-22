<?php

use App\Controllers\HomeController;
use App\Controllers\ReactController;

// Register React page URLs here too so refresh/deep links reach the app shell.
$router->get('/', [ReactController::class, 'index']);
foreach (['/calendar', '/history', '/settings', '/admin', '/admin/login', '/admin/schedules', '/admin/settings'] as $page) {
    $router->get($page, [ReactController::class, 'index']);
}
$router->get('/results/{id}', fn (string $id) => (new ReactController())->index());

// Keep the original PHP example available.
$router->get('/starter', [HomeController::class, 'index']);
$router->post('/welcome', [HomeController::class, 'welcome']);
