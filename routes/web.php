<?php

use Headdetect\Overseer\Http\Controllers\MapFeedController;
use Headdetect\Overseer\Http\Controllers\MapSurfaceController;
use Headdetect\Overseer\Http\Controllers\MapTileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('/overseer/servers/{server:uuid}')
    ->group(function () {
        Route::get('/map/{path}', MapTileController::class)
            ->where('path', 'tiles/.+')
            ->name('overseer.map.tile');
        Route::get('/map-feed', MapFeedController::class)
            ->name('overseer.map.feed');
        Route::get('/map-surface', MapSurfaceController::class)
            ->name('overseer.map.surface');
    });
