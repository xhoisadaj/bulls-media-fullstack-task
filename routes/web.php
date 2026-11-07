<?php

use App\Http\Controllers\GoogleOAuthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('auth')->group(function () {
    Route::get('/google/connect', [GoogleOAuthController::class, 'redirect'])->name('google.connect');
    Route::get('/google/callback', [GoogleOAuthController::class, 'callback'])->name('google.callback');
});
