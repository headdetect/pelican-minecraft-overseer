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
        // Each request loads a chunk and summons a marker, so cap how often one user can ask.
        Route::get('/map-surface', MapSurfaceController::class)
            ->middleware('throttle:30,1')
            ->name('overseer.map.surface');
    });
