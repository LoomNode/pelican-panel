<?php

use App\Http\Controllers\Base\ModIconController;
use App\Http\Controllers\Base\WorkshopIconController;
use App\Http\Controllers\UpdateStatusController;
use App\Http\Middleware\RedirectIfNotInstalled;
use App\Livewire\Installer\PanelInstaller;
use Illuminate\Support\Facades\Route;

Route::get('installer', PanelInstaller::class)->name('installer')
    ->withoutMiddleware(['auth', RedirectIfNotInstalled::class]);

Route::get('update/status', UpdateStatusController::class)->name('update.status')
    ->middleware('auth');

// Mod pictures for the Mods page (LoomNode): signed-in users only, pictures from Modrinth's file server only.
Route::get('mod-icon/{project}/{file}', ModIconController::class)
    ->where(['project' => '[A-Za-z0-9]{4,16}', 'file' => '[A-Za-z0-9_-]{1,100}\.(png|jpe?g|webp|gif)'])
    ->middleware(['auth', 'throttle:600,1'])
    ->name('loomnode.mod-icon');

// Steam Workshop pictures for the Don't Starve Together Mods page (LoomNode): the same, from Steam's picture server only.
Route::get('workshop-icon/{file}/{hash}', WorkshopIconController::class)
    ->where(['file' => '[0-9]{6,24}', 'hash' => '[A-Fa-f0-9]{16,64}'])
    ->middleware(['auth', 'throttle:600,1'])
    ->name('loomnode.workshop-icon');
