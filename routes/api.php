<?php

use App\Controllers\ApiController;
use App\Controllers\LotteryController as Lottery;

// Same PHP sessions and CSRF protection as web routes; no database required.
$router->get('/api/health', [ApiController::class, 'health']);
$router->get('/health', [ApiController::class, 'health']);
$router->get('/api/session', [Lottery::class, 'session']);
$router->post('/api/welcome', [ApiController::class, 'welcome']);

$router->get('/api/results', [Lottery::class, 'day']);
$router->get('/api/results/{id}', [Lottery::class, 'result']);
$router->get('/api/calendar', [Lottery::class, 'calendar']);
$router->get('/api/history', [Lottery::class, 'history']);
$router->post('/api/admin/login', [Lottery::class, 'login']);
$router->post('/api/admin/logout', [Lottery::class, 'logout'], true);
$router->post('/api/admin/password', [Lottery::class, 'password'], true);
$router->get('/api/admin/schedules', [Lottery::class, 'schedules'], true);
$router->post('/api/admin/schedules', [Lottery::class, 'createSchedule'], true);
$router->add('PUT', '/api/admin/schedules/{id}', [Lottery::class, 'updateSchedule'], true);
$router->add('DELETE', '/api/admin/schedules/{id}', [Lottery::class, 'deleteSchedule'], true);
$router->post('/api/admin/results', [Lottery::class, 'createResult'], true);
$router->add('PUT', '/api/admin/results/{id}', [Lottery::class, 'updateResult'], true);
$router->add('DELETE', '/api/admin/results/{id}', [Lottery::class, 'deleteResult'], true);
