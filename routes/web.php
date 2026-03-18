<?php

use App\Http\Controllers\web\v1\GoogleController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function () {
    /*foreach (\App\Models\TypeSite::all() as $type) {
        $type->update([
            'slug' => Str::slug($type->name),
        ]);
    }*/
    phpinfo();
});

Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('google.login');

Route::get('/auth/google/callback', [GoogleController::class, 'callback']);
