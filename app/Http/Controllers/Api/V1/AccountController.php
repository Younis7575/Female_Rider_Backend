<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\DeviceToken;
use App\Models\Trip;
use App\Models\UserBlock;
use App\Services\Driver\DriverOnboardingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $trip = $user->activeTrip();

        return ApiResponse::ok([
            'user' => new UserResource($user),
            'active_trip_id' => $trip?->uuid,
            'active_ride_id' => $user->rideRequests()->whereIn('status', ['searching', 'offers_available'])->where('expires_at', '>', now())->value('uuid'),
            'unread_notifications' => $user->appNotifications()->whereNull('read_at')->count(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:80', 'regex:/^[\pL\s\.\'-]+$/u'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:120'],
            'locale' => ['sometimes', Rule::in(['en', 'ur'])],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[\d\s\-]{7,16}$/'],
            'notification_preferences' => ['sometimes', 'array'],
            'notification_preferences.*' => ['boolean'],
            'privacy_preferences' => ['sometimes', 'array'],
            'privacy_preferences.*' => ['boolean'],
        ]);
        $user = $request->user();
        $user->fill($data);
        if ($user->name && ! $user->profile_completed_at) {
            $user->profile_completed_at = now();
        }
        $user->save();

        return ApiResponse::ok(new UserResource($user->fresh()), 'Profile updated.');
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=200,min_height=200']]);
        $user = $request->user();
        $old = $user->photo_path;
        $user->forceFill(['photo_path' => $request->file('photo')->store("avatars/{$user->uuid}", 'public')])->save();
        if ($old) {
            \Storage::disk('public')->delete($old);
        }

        return ApiResponse::ok(new UserResource($user), 'Photo updated.');
    }

    public function switchMode(Request $request, DriverOnboardingService $drivers): JsonResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['customer', 'driver'])]]);
        $user = $drivers->switchMode($request->user(), $data['mode']);

        return ApiResponse::ok(new UserResource($user->fresh()), 'Switched to '.ucfirst($data['mode']).' mode.');
    }

    public function requestDeletion(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $user = $request->user();
        if ($user->activeTrip()) {
            return ApiResponse::error('Finish your active trip before deleting your account.', 'active_trip_exists', 409);
        }
        if ($user->driverProfile?->is_online) {
            app(DriverOnboardingService::class)->goOffline($user);
        }
        $user->forceFill(['account_status' => 'pending_deletion', 'deletion_requested_at' => now(), 'deletion_reason' => $data['reason'] ?? null])->save();
        $user->tokens()->delete();
        $user->deviceTokens()->update(['revoked_at' => now()]);
        AuditLog::record('account.deletion_requested', $user, [], [], [], $user);

        return ApiResponse::ok(null, 'Your deletion request has been received. Your data will be removed after the legally required retention period. Signing in again within 30 days cancels the request.');
    }

    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
            'device_id' => ['nullable', 'string', 'max:120'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:20'],
        ]);
        $hash = hash('sha256', $data['token']);
        // A token belongs to exactly one account (re-assign on login switch).
        $device = DeviceToken::query()->updateOrCreate(['token_hash' => $hash], [
            'user_id' => $request->user()->id,
            'token' => $data['token'],
            'platform' => $data['platform'],
            'device_id' => $data['device_id'] ?? null,
            'device_name' => $data['device_name'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'last_seen_at' => now(),
            'revoked_at' => null,
            'failure_count' => 0,
        ]);

        return ApiResponse::ok(['id' => $device->id], 'Device registered.');
    }

    public function removeDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $request->user()->deviceTokens()->where('token_hash', hash('sha256', $data['token']))->update(['revoked_at' => now()]);

        return ApiResponse::ok(null, 'Device removed.');
    }

    public function block(Request $request): JsonResponse
    {
        $data = $request->validate(['trip_id' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:255']]);
        $trip = Trip::query()->where('uuid', $data['trip_id'])->firstOrFail();
        $me = $request->user();
        if (! $trip->involves($me)) {
            return ApiResponse::error('Trip not found.', 'not_found', 404);
        }
        $other = $trip->customer_id === $me->id ? $trip->driver_id : $trip->customer_id;
        UserBlock::query()->firstOrCreate(['blocker_id' => $me->id, 'blocked_id' => $other], ['reason' => $data['reason'] ?? null]);

        return ApiResponse::ok(null, 'You will not be matched with this person again.');
    }
}
