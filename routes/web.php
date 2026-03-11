<?php

use App\Http\Controllers\web\v1\GoogleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    phpinfo();
});

Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('google.login');

Route::get('/auth/google/callback', [GoogleController::class, 'callback']);