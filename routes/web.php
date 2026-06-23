<?php

use App\Http\Controllers\web\PageController;
#use App\Http\Controllers\web\v1\FacebookWebhookController;
use App\Http\Controllers\web\v1\GoogleController;
use App\Http\Controllers\web\v4\FacebookConnectController;
use App\Http\Controllers\web\v4\FacebookWebhookController;
use App\Http\Controllers\web\v4\InstagramConnectController;
use App\Http\Controllers\web\v4\InstagramWebhookController;
use App\Http\Controllers\web\v4\SlackConnectController;
use App\Http\Controllers\web\v4\SlackWebhookController;
use App\Http\Controllers\web\v4\TelegramConnectController;
use App\Http\Controllers\web\v4\TelegramWebhookController;
use App\Http\Controllers\web\v4\YouTubeConnectController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function () {
    /*foreach (\App\Models\TypeSite::all() as $type) {
        $type->update([
            'slug' => Str::slug($type->name),
        ]);
    }*/
    return redirect()->route('home.page');
});

Route::controller(PageController::class)->group(function () {
    Route::get('/accueil', 'home')->name('home.page');
    Route::get('/a-propos', 'about')->name('about.page');
    Route::get('services', 'services')->name('services.page');
    Route::get('service/{slug}', 'service')->name('service.single');
    Route::get('/tarifs', 'abonnements')->name('abonnements.page');
    Route::get('/faqs', 'faqs')->name('faqs.page');
    Route::get('/contact', 'contact')->name('contact.page');
    Route::get('/politique-de-confidentialite', 'politique_de_confidentialite')->name('politique_de_confidentialite.page');
});

Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('google.login');

Route::get('/auth/google/callback', [GoogleController::class, 'callback']);

/*Route::prefix('webhooks/facebook')->group(function () {
    Route::get('/', [ FacebookWebhookController::class, 'verify' ]);
    Route::post('/', [ FacebookWebhookController::class, 'handle' ]);
});*/

Route::prefix('webhooks')->group(function () {

    Route::prefix('/facebook')->controller(FacebookWebhookController::class)->group(function (){
        Route::get('/', 'verify');
        Route::post('/', 'handle');
    });

    Route::prefix('/instagram')->controller(InstagramWebhookController::class)->group(function (){
        Route::get('/', 'verify');
        Route::post('/', 'handle');
    });

    Route::prefix('/slack')->controller(SlackWebhookController::class)->group(function (){
        Route::post('/', 'handle');
    });

    Route::prefix('/telegram')->controller(TelegramWebhookController::class)->group(function (){
        Route::post('/{accountId}', 'handle')->name('webhook.telegram');
    });

});

Route::prefix('social')->group(function (){

    Route::prefix('/facebook')->controller(FacebookConnectController::class)->group(function (){
        Route::get('/connect/{site}', 'redirect');
        Route::get('/callback', 'callback');
    });

    Route::prefix('/youtube')->controller(YouTubeConnectController::class)->group(function (){
        Route::get('/redirect/{siteId}','redirect');
        Route::get('/callback','callback');
    });

    Route::prefix('/instagram')->controller(InstagramConnectController::class)->group(function (){
        Route::get('/redirect/{siteId}','redirect');
        Route::get('/callback','callback');
    });

    Route::prefix('/slack')->controller(SlackConnectController::class)->group(function (){
        Route::get('/connect/{siteId}','redirect');
        Route::get('/callback','callback');
    });

    Route::prefix('/telegram')->controller(TelegramConnectController::class)->group(function (){
        Route::post('/connect/{siteId}','connect');
        Route::delete('/disconnect/{siteId}','disconnect');
    });

});

Route::get('/app/{any?}', function () {
    return response()->file(
        public_path('angular/index.html')
    );
})->where('any', '.*');
