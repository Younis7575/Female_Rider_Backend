<?php

use App\Models\AdminUser;
use App\Models\RideRequest;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Private channel authorization. Mobile users authenticate with Sanctum tokens at
| /api/v1/broadcasting/auth; admins authenticate with the "admin" session guard at
| /broadcasting/auth. Every subscription is authorized here.
*/

Broadcast::channel('user.{userId}', fn ($user, $userId) => $user instanceof User && (int) $user->id === (int) $userId);

Broadcast::channel('driver.{userId}', fn ($user, $userId) => $user instanceof User
    && (int) $user->id === (int) $userId
    && $user->driverProfile !== null);

Broadcast::channel('ride.{rideUuid}', function ($user, string $rideUuid) {
    if ($user instanceof AdminUser) {
        return $user->can('rides.view');
    }
    $ride = RideRequest::query()->where('uuid', $rideUuid)->first();

    return $ride && $user instanceof User && $ride->customer_id === $user->id;
}, ['guards' => ['sanctum', 'admin']]);

Broadcast::channel('trip.{tripUuid}', function ($user, string $tripUuid) {
    if ($user instanceof AdminUser) {
        return $user->can('rides.view');
    }
    $trip = Trip::query()->where('uuid', $tripUuid)->first();

    return $trip && $user instanceof User && $trip->involves($user);
}, ['guards' => ['sanctum', 'admin']]);

Broadcast::channel('admin.operations', fn ($user) => $user instanceof AdminUser && $user->can('operations.view'), ['guards' => ['admin']]);
