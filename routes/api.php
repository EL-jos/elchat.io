<?php
ini_set('max_execution_time', 0);
set_time_limit(0);

use App\Http\Controllers\api\v1\AIRoleController;
use App\Http\Controllers\api\v1\ChatController;
use App\Http\Controllers\api\v1\ChunkController;
use App\Http\Controllers\api\v1\ConversationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\api\v1\DocumentController;
use App\Http\Controllers\api\v1\ManualContentController;
use App\Http\Controllers\api\v1\PageController;
use App\Http\Controllers\api\v1\SitemapController;
use App\Http\Controllers\api\v1\TypeSiteController;
use App\Http\Controllers\api\v1\UserController;
use App\Http\Controllers\api\v1\WidgetSettingController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\api\v1\SiteController;
use App\Http\Controllers\api\v1\AuthController;
use Illuminate\Support\Facades\Log;

Route::prefix('v1')->group(function () {

    Route::controller(AuthController::class)->group(function () {
        Route::post('/register', 'register')->name('api.register');
        Route::post('/verify-code', 'verify')->name('api.verify');
        Route::post('/resend-code', 'resend')->name('api.resend-code');
        Route::post('/login', 'login')->name('api.login');
        Route::post('/logout', 'logout')->name('api.logout')->middleware('jwt.auth');
        Route::post('/refresh-token', 'refreshToken')->name('api.refresh-token')->middleware('jwt.auth');
        Route::post('/forgot-password', 'sendPasswordResetCode')->name('api.send-password-reset-code');
        Route::post('/reset-password', 'resetPasswordWithCode')->name('api.reset-password-with-code');
        Route::get('/me', 'me')->name('api.me')->middleware('jwt.auth');
    });
    Route::middleware('jwt.auth')->group(function () {
        Route::controller(DashboardController::class)->group(function () {
            Route::get('/dashboard/overview', 'overview');
            Route::get('/dashboard/site/{id}/overview', 'siteOverview');
        });
        Route::apiResource('site', SiteController::class);
        Route::controller(SiteController::class)->group(function () {
            Route::post('site/{id}/crawl', 'crawl');
            //Route::post('site/{site_id}/documents', 'uploadDocument');
            Route::get('site/{siteId}/pages/overview', 'pagesOverview');
            Route::get('site/{site}/widget-test', 'widgetTest');
            Route::get('/site/{site_id}/widget/config', 'widgetConfig');
            Route::post('/site/sitemap', 'generateSitemap');
            Route::post('/knowledge-quality/calculate', 'calculateKnowledgeQuality');
            //Route::post('/api/products/{productIndex}/reindex', 'reindexProducts');
        });
        Route::post('/chat/ask', [ChatController::class, 'ask']);
        Route::apiResource('conversation', ConversationController::class)->except(['store', 'update',]);
        Route::controller(ConversationController::class)->group(function () {
            Route::get('/conversation/{conversationId}/{siteId}', 'messages');
            Route::get('/conversation/{conversationId}/{siteId}/admin', 'messagesAdmin');
            Route::get('/conversation/{conversationId}/site/{siteId}/user/{userId}', 'messagesByUser');
            Route::get('/site/{siteId}/users/{userId}/conversations', "conversationsByUser");
        });
        Route::post('/site/{site}/manual-content', [ManualContentController::class, 'store']);
        Route::post('/site/{site}/sitemap', [SitemapController::class, 'store']);
        Route::post('/site/{site}/documents', [DocumentController::class, 'store']);
        Route::apiResource('type_site', TypeSiteController::class)->only(['index']);
        Route::apiResource('widget_setting', WidgetSettingController::class)->except(['index']);
        Route::controller(WidgetSettingController::class)->group(function () {
            Route::get('site/{site}/widget/setting', 'index');
        });
        Route::apiResource('ai_role', AIRoleController::class);
        Route::controller(ChunkController::class)->group(function () {
            Route::get('chunk/{site}/products', 'indexProducts');
            Route::post('chunk/product/{site}/{document_id}/{product_index}/reindex', 'reindexProduct');
        });
        Route::controller(PageController::class)->group(function () {
            Route::post("/pages/{page}/recrawl", "recrawl");
            Route::post("site/{site}/pages/import", "import");
            Route::delete('/pages', [PageController::class, 'destroyMultiple']);
            Route::delete('/pages/{page}', [PageController::class, 'destroy']);
        });
        Route::controller(UserController::class)->group(function (){
            Route::get('/users/site/{site}', 'index')->whereUuid('site');
            Route::get('users/{userId}/site/{site}', 'show')->whereUuid(['userId', 'site']);
        });
    });
    Route::controller(SiteController::class)->group(function () {
        Route::get('/site/{site_id}/widget/config', 'widgetConfig');
    });
});

