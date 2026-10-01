<?php

use Headdetect\Underseer\Http\Controllers\MapTileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])
    ->prefix('/underseer/servers/{server:uuid}')
    ->group(function () {
        Route::get('/map/{path}', MapTileController::class)
            ->where('path', 'tiles/.+')
            ->name('underseer.map.tile');
    });
