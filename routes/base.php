<?php

use App\Http\Controllers\Base\ModIconController;
use App\Http\Middleware\RedirectIfNotInstalled;
use App\Livewire\Installer\PanelInstaller;
use Illuminate\Support\Facades\Route;

Route::get('installer', PanelInstaller::class)->name('installer')
    ->withoutMiddleware(['auth', RedirectIfNotInstalled::class]);

// Mod pictures for the Mods page (LoomNode): signed-in users only, pictures from Modrinth's file server only.
Route::get('mod-icon/{project}/{file}', ModIconController::class)
    ->where(['project' => '[A-Za-z0-9]{4,16}', 'file' => '[A-Za-z0-9_-]{1,100}\.(png|jpe?g|webp|gif)'])
    ->middleware(['auth', 'throttle:600,1'])
    ->name('loomnode.mod-icon');
