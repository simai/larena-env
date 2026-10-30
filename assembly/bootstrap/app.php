<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Larena\Env\Panel\PanelEnvironment;

// The panel's own defaults and its application key come before the configuration is read: a release has only the
// server's .env, and that file may hold nothing but the database and the setup token.
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(health: '/up')
    ->withMiddleware(static function (Middleware $middleware): void {
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
    })
    ->create();

$app->afterLoadingEnvironment(static fn () => PanelEnvironment::apply($app->environmentFilePath()));

return $app;
