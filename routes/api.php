<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Simtabi\Laranail\DBConsole\Http\Api\Controllers\AccountController;
use Simtabi\Laranail\DBConsole\Http\Api\Controllers\AuditController;
use Simtabi\Laranail\DBConsole\Http\Api\Controllers\DatabaseController;
use Simtabi\Laranail\DBConsole\Http\Api\Controllers\GrantController;
use Simtabi\Laranail\DBConsole\Http\Api\Controllers\ServerController;
use Simtabi\Laranail\DBConsole\Http\Api\Controllers\WebhookController;

/*
 * DBConsole REST API. Loaded only when laranail.db-console.api.enabled is
 * true (hasRoutesWhen). Every route is behind the laranail-db-console.api-guard
 * middleware (HTTPS + auth guard + IP allow-list); authorization itself
 * happens inside the services (same Gate as the CLI/UI).
 */
Route::prefix((string) config('laranail.db-console.api.prefix', 'api/db-console'))
    ->middleware(['laranail-db-console.api-guard'])
    ->group(function (): void {
        Route::get('servers', [ServerController::class, 'index']);

        Route::get('servers/{server}/databases', [DatabaseController::class, 'index']);
        Route::post('servers/{server}/databases', [DatabaseController::class, 'store']);
        Route::delete('servers/{server}/databases/{name}', [DatabaseController::class, 'destroy']);

        Route::get('servers/{server}/accounts', [AccountController::class, 'index']);
        Route::post('servers/{server}/accounts', [AccountController::class, 'store']);
        Route::delete('servers/{server}/accounts/{username}', [AccountController::class, 'destroy']);

        Route::post('servers/{server}/grants', [GrantController::class, 'store']);

        Route::get('audit', [AuditController::class, 'index']);

        Route::get('webhooks', [WebhookController::class, 'index']);
        Route::post('webhooks', [WebhookController::class, 'store']);
        Route::delete('webhooks/{id}', [WebhookController::class, 'destroy']);
    });
