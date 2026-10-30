<?php

declare(strict_types=1);

use Larena\Env\Http\Controllers\ServerAdminController;

/** @var Illuminate\Routing\Router $router */
$router->get('/', [ServerAdminController::class, 'index'])->name('larena.env.admin.index');
