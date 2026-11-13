<?php

use App\Http\Controllers\Website\CmsPageController;
use App\Http\Controllers\Website\ContactController;
use App\Http\Controllers\Website\PageController;
use App\Http\Controllers\Website\SitemapController;
use App\Http\Controllers\Website\TripShareController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public marketing website
|--------------------------------------------------------------------------
*/

Route::name('website.')->group(function () {
    Route::get('/', [PageController::class, 'home'])->name('home');
    Route::get('/about', [PageController::class, 'about'])->name('about');
    Route::get('/safety', [PageController::class, 'safety'])->name('safety');
    Route::get('/drive', [PageController::class, 'drive'])->name('drive');
    Route::get('/faq', [PageController::class, 'faq'])->name('faq');

    Route::get('/contact', [ContactController::class, 'show'])->name('contact');
    Route::post('/contact', [ContactController::class, 'store'])
        ->middleware('throttle:5,1,website-contact')
        ->name('contact.store');

    // Legal / policy pages rendered from the admin-managed CMS.
    Route::get('/{slug}', [CmsPageController::class, 'show'])
        ->whereIn('slug', CmsPageController::LEGAL_SLUGS)
        ->name('legal');
    Route::get('/pages/{slug}', [CmsPageController::class, 'show'])
        ->where('slug', '[a-z0-9\-]+')
        ->name('page');

    // Public live-trip share link (sent by riders to trusted contacts).
    Route::get('/trip/{token}', [TripShareController::class, 'show'])
        ->where('token', '[A-Za-z0-9_\-]{1,128}')
        ->middleware('throttle:60,1,website-trip-page')
        ->name('trip.share');
    Route::get('/trip/{token}/status', [TripShareController::class, 'status'])
        ->where('token', '[A-Za-z0-9_\-]{1,128}')
        ->middleware('throttle:120,1,website-trip-status')
        ->name('trip.status');

    Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
});
