<?php

use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Auth\SignInLinkController;
use Illuminate\Support\Facades\Route;

Route::redirect('/login', '/login')->name('auth.login');

// One-time links from the Application API (SignInLinkService); signed-in browsers too.
Route::get('/sign-in-link/{token}', SignInLinkController::class)
    ->where('token', '[A-Za-z0-9_-]{40,64}')
    ->withoutMiddleware('guest')
    ->name('auth.sign-in-link');

Route::prefix('oauth')->group(function () {
    Route::get('/redirect/{driver}', [OAuthController::class, 'redirect'])->name('auth.oauth.redirect');
    Route::get('/callback/{driver}', [OAuthController::class, 'callback'])->name('auth.oauth.callback')->withoutMiddleware('guest');
});
