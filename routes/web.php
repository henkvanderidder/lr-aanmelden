<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OpenstreetmapController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/openstreetmap', [OpenstreetmapController::class, 'index'])->name('openstreetmap');
    Route::get('/openstreetmap/getAuthorizationUrl', [OpenstreetmapController::class, 'getAuthorizationUrl']);
    Route::get('/openstreetmap/getAccessToken', [OpenstreetmapController::class, 'getAccessToken']);
    Route::get('/openstreetmap/testAccessToken', [OpenstreetmapController::class, 'testAccessToken']);
});


require __DIR__.'/auth.php';
