<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\DeployController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\DriverFinanceController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PlacesController;
use App\Http\Controllers\Api\V1\RideController;
use App\Http\Controllers\Api\V1\SafetyController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TripChatController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FemaleRider REST API v1  (prefix: /api/v1)
|--------------------------------------------------------------------------
| Response envelope: { success, message, data, meta, error }
| Auth: Laravel Sanctum bearer tokens. All business rules are enforced server-side.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    // ---- Public ----------------------------------------------------------
    Route::get('config', [ContentController::class, 'config'])->name('config');
    Route::get('health', [ContentController::class, 'health'])->name('health');
    Route::post('deploy/notify', [DeployController::class, 'notify'])->middleware('throttle:10,1')->name('deploy.notify');
    Route::get('faqs', [ContentController::class, 'faqs']);
    Route::get('pages/{slug}', [ContentController::class, 'page']);

    Route::post('auth/otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp')->name('auth.otp.request');
    Route::post('auth/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify')->name('auth.otp.verify');

    Route::post('webhooks/payments/{provider}', [PaymentController::class, 'webhook'])->middleware('throttle:60,1')->where('provider', '[a-z0-9_]+');

    // ---- Authenticated ---------------------------------------------------
    Route::middleware(['auth:sanctum', 'account.active', 'throttle:api'])->group(function () {

        Route::post('broadcasting/auth', fn (Request $r) => Broadcast::auth($r))->name('broadcasting.auth');

        // Account & sessions
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('sessions', [AuthController::class, 'sessions']);
        Route::delete('sessions/{id}', [AuthController::class, 'revokeSession'])->whereNumber('id');
        Route::post('sessions/revoke-others', [AuthController::class, 'revokeOtherSessions']);

        Route::get('me', [AccountController::class, 'me']);
        Route::put('me', [AccountController::class, 'update']);
        Route::post('me/photo', [AccountController::class, 'uploadPhoto']);
        Route::post('me/mode', [AccountController::class, 'switchMode']);
        Route::post('account/delete', [AccountController::class, 'requestDeletion']);
        Route::post('devices', [AccountController::class, 'registerDevice']);
        Route::delete('devices', [AccountController::class, 'removeDevice']);
        Route::post('users/block', [AccountController::class, 'block']);

        // Eligibility verification
        Route::get('verification', [VerificationController::class, 'show']);
        Route::post('verification', [VerificationController::class, 'submit'])->middleware('throttle:10,60');
        Route::post('verification/appeal', [VerificationController::class, 'appeal']);

        // Maps, estimates, saved places, coupons
        Route::get('places/search', [PlacesController::class, 'search'])->middleware('throttle:60,1');
        Route::get('places/reverse', [PlacesController::class, 'reverse'])->middleware('throttle:60,1');
        Route::get('places/recent', [PlacesController::class, 'recent']);
        Route::post('service-area/check', [PlacesController::class, 'serviceArea']);
        Route::post('fare/estimate', [PlacesController::class, 'estimate']);
        Route::post('routes/estimate', [PlacesController::class, 'estimate']);
        Route::get('saved-places', [PlacesController::class, 'savedIndex']);
        Route::post('saved-places', [PlacesController::class, 'savedStore']);
        Route::delete('saved-places/{id}', [PlacesController::class, 'savedDestroy'])->whereNumber('id');
        Route::get('coupons', [PlacesController::class, 'coupons']);
        Route::post('coupons/validate', [PlacesController::class, 'validateCoupon']);

        // Customer rides & negotiation
        Route::post('rides', [RideController::class, 'store'])->middleware(['idempotency', 'throttle:booking']);
        Route::get('rides', [RideController::class, 'index']);
        Route::get('rides/active', [RideController::class, 'active']);
        Route::get('rides/{ride}', [RideController::class, 'show']);
        Route::get('rides/{ride}/offers', [RideController::class, 'offers']);
        Route::post('rides/{ride}/cancel', [RideController::class, 'cancel']);
        Route::post('rides/{ride}/fare', [RideController::class, 'updateFare']);
        Route::post('offers/{offer}/accept', [RideController::class, 'acceptOffer'])->middleware('idempotency');
        Route::post('offers/{offer}/reject', [RideController::class, 'rejectOffer']);
        Route::post('offers/{offer}/counter', [RideController::class, 'counterOffer']);
        Route::post('offers/{offer}/withdraw', [DriverController::class, 'withdraw']);
        Route::post('offers/{offer}/respond', [DriverController::class, 'respondToCounter'])->middleware('idempotency');

        // Driver onboarding, presence, requests
        Route::prefix('driver')->group(function () {
            Route::get('profile', [DriverController::class, 'profile']);
            Route::get('status', [DriverController::class, 'profile']);
            Route::put('profile', [DriverController::class, 'updateProfile']);
            Route::put('vehicle', [DriverController::class, 'updateVehicle']);
            Route::get('documents', [DriverController::class, 'documents']);
            Route::post('documents', [DriverController::class, 'uploadDocument'])->middleware('throttle:30,10');
            Route::post('submit', [DriverController::class, 'submit']);
            Route::put('payout-account', [DriverController::class, 'updatePayoutAccount']);
            Route::post('online', [DriverController::class, 'online']);
            Route::post('offline', [DriverController::class, 'offline']);
            Route::post('location', [DriverController::class, 'location'])->middleware('throttle:location');
            Route::get('requests', [DriverController::class, 'requests']);
            Route::post('requests/{ride}/offer', [DriverController::class, 'offer']);
            Route::post('requests/{ride}/decline', [DriverController::class, 'decline']);
            Route::get('dashboard', [DriverFinanceController::class, 'dashboard']);
            Route::get('earnings', [DriverFinanceController::class, 'earnings']);
            Route::get('ledger', [DriverFinanceController::class, 'ledger']);
            Route::get('statements', [DriverFinanceController::class, 'statement']);
            Route::get('payouts', [DriverFinanceController::class, 'payouts']);
            Route::post('payouts', [DriverFinanceController::class, 'requestPayout'])->middleware('idempotency');
        });

        // Trips
        Route::get('trips', [TripController::class, 'index']);
        Route::get('trips/active', [TripController::class, 'active']);
        Route::get('trips/{trip}', [TripController::class, 'show']);
        Route::post('trips/{trip}/en-route', [TripController::class, 'enRoute']);
        Route::post('trips/{trip}/arrive', [TripController::class, 'arrive']);
        Route::post('trips/{trip}/start', [TripController::class, 'start'])->middleware('throttle:10,1');
        Route::post('trips/{trip}/complete', [TripController::class, 'complete'])->middleware('idempotency');
        Route::post('trips/{trip}/cancel', [TripController::class, 'cancel']);
        Route::post('trips/{trip}/location', [TripController::class, 'location'])->middleware('throttle:location');
        Route::post('trips/{trip}/share', [TripController::class, 'share']);
        Route::delete('trips/{trip}/share', [TripController::class, 'revokeShare']);
        Route::post('trips/{trip}/cash-collected', [TripController::class, 'cashCollected'])->middleware('idempotency');
        Route::get('trips/{trip}/receipt', [TripController::class, 'receipt']);
        Route::post('trips/{trip}/rating', [TripController::class, 'rate']);
        Route::get('trips/{trip}/messages', [TripChatController::class, 'index']);
        Route::post('trips/{trip}/messages', [TripChatController::class, 'store'])->middleware('throttle:chat');
        Route::post('trips/{trip}/messages/read', [TripChatController::class, 'read']);

        // Payments & refunds
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/{uuid}', [PaymentController::class, 'show'])->whereUuid('uuid');
        Route::get('refunds', [PaymentController::class, 'refunds']);
        Route::post('refunds', [PaymentController::class, 'requestRefund'])->middleware('idempotency');

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);

        // Safety & support
        Route::post('sos', [SafetyController::class, 'sos'])->middleware('throttle:10,1');
        Route::get('incidents', [SafetyController::class, 'index']);
        Route::post('incidents', [SafetyController::class, 'store'])->middleware('throttle:10,10');
        Route::get('support/tickets', [SupportController::class, 'index']);
        Route::post('support/tickets', [SupportController::class, 'store']);
        Route::get('support/tickets/{ticket}', [SupportController::class, 'show']);
        Route::post('support/tickets/{ticket}/messages', [SupportController::class, 'reply']);
    });
});
