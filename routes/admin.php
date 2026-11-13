<?php

use App\Http\Controllers\Admin\SecureFileController;
use Illuminate\Support\Facades\Route;

// Admin-only helper routes (outside Filament resources). Session guard "admin".
Route::middleware(['web', 'auth:admin'])->prefix('admin/secure')->name('admin.secure.')->group(function () {
    Route::get('files/{type}/{id}/{variant?}', [SecureFileController::class, 'show'])
        ->whereIn('type', ['driver-document', 'eligibility', 'evidence'])->whereNumber('id')
        ->middleware('throttle:60,1')->name('file');
});
