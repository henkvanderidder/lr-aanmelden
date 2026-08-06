<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OpenstreetmapController;

Route::get('/openstreetmap', [OpenstreetmapController::class, 'index']);
Route::get('/openstreetmap/getAuthorizationUrl', [OpenstreetmapController::class, 'getAuthorizationUrl']);
Route::get('/openstreetmap/getAccessToken', [OpenstreetmapController::class, 'getAccessToken']);
Route::get('/openstreetmap/testAccessToken', [OpenstreetmapController::class, 'testAccessToken']);

Route::get('/app', function () {
    return "index page of app";
});
