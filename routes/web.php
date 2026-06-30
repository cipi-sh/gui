<?php

use CipiGui\Http\Controllers\Auth\LoginController;
use CipiGui\Http\Controllers\Auth\TwoFactorController;
use CipiGui\Livewire\AppDetail;
use CipiGui\Livewire\Apps;
use CipiGui\Livewire\Dashboard;
use CipiGui\Livewire\Databases;
use CipiGui\Livewire\Settings;
use CipiGui\Livewire\Servers;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('cipi-gui.login');
    Route::post('/login', [LoginController::class, 'login'])->name('cipi-gui.login.submit');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('cipi-gui.logout');

Route::middleware(['auth'])->group(function () {
    Route::get('/2fa', [TwoFactorController::class, 'show'])->name('cipi-gui.2fa');
    Route::post('/2fa', [TwoFactorController::class, 'verify'])->name('cipi-gui.2fa.verify');
});

Route::middleware(['auth', 'cipi-gui.2fa'])->group(function () {
    Route::get('/', Dashboard::class)->name('cipi-gui.dashboard');
    Route::get('/servers', Servers::class)->name('cipi-gui.servers');
    Route::get('/apps', Apps::class)->name('cipi-gui.apps');
    Route::get('/apps/{name}', AppDetail::class)->name('cipi-gui.apps.show');
    Route::get('/databases', Databases::class)->name('cipi-gui.databases');
    Route::get('/settings', Settings::class)->name('cipi-gui.settings');
});
