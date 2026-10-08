<?php

use Headdetect\Overseer\Http\Controllers\Api;
use Headdetect\Overseer\Http\Controllers\AssetController;
use Headdetect\Overseer\Http\Controllers\MapFeedController;
use Headdetect\Overseer\Http\Controllers\MapSurfaceController;
use Headdetect\Overseer\Http\Controllers\MapTileController;
use Illuminate\Support\Facades\Route;

// The built React app. Not secret, but only signed-in users load it.
Route::middleware(['web', 'auth'])
    ->get('/overseer/assets/{file}', AssetController::class)
    ->where('file', 'overseer\.(js|css)')
    ->name('overseer.asset');

Route::middleware(['web', 'auth'])
    ->prefix('/overseer/servers/{server:uuid}')
    ->group(function () {
        Route::get('/map/{path}', MapTileController::class)
            ->where('path', 'tiles/.+')
            ->name('overseer.map.tile');
        Route::get('/map-feed', MapFeedController::class)
            ->name('overseer.map.feed');
        // Each request loads a chunk and summons a marker, so cap how often one user can ask.
        Route::get('/map-surface', MapSurfaceController::class)
            ->middleware('throttle:30,1')
            ->name('overseer.map.surface');

        // The React app's API. Each controller checks the permission the action needs.
        Route::prefix('/api')->group(function () {
            Route::get('/stats', [Api\OverviewController::class, 'stats']);
            Route::get('/chunky', [Api\OverviewController::class, 'chunky']);
            Route::get('/map', [Api\OverviewController::class, 'map']);
            Route::post('/map', [Api\OverviewController::class, 'map']);
            Route::get('/recent', [Api\OverviewController::class, 'recent']);
            Route::get('/rcon', [Api\OverviewController::class, 'rcon']);

            Route::post('/commands/{name}', [Api\CommandController::class, 'run'])->where('name', '[a-z]+');
            Route::post('/chat', [Api\CommandController::class, 'chat']);

            Route::get('/players', [Api\PlayerController::class, 'index']);
            Route::get('/online', [Api\PlayerController::class, 'online']);
            Route::get('/players/{player}/gamemode', [Api\PlayerController::class, 'gamemode']);
            Route::post('/players/{player}/{action}', [Api\PlayerController::class, 'act'])->where('action', '[a-z-]+');

            Route::get('/tools', [Api\ToolsController::class, 'show']);
            Route::post('/tools/chunky/{action}', [Api\ToolsController::class, 'chunky'])->where('action', '[a-z]+');
            Route::post('/tools/render', [Api\ToolsController::class, 'render']);

            Route::get('/config', [Api\ConfigController::class, 'show']);
            Route::post('/config', [Api\ConfigController::class, 'save']);
            Route::post('/config/restart', [Api\ConfigController::class, 'restart']);
            Route::get('/config/files', [Api\ConfigController::class, 'files']);
        });
    });
