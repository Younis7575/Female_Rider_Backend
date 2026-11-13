<?php

namespace App\Http\Controllers\Website;

use App\Enums\TripStatus;
use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Services\Dispatch\DriverPresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Public, unauthenticated live-trip view for a rider's trusted contacts.
 *
 * Exposes the minimum needed to follow a trip: status, driver first name, vehicle
 * description + plate, pickup/destination (addresses and ~100 m-rounded areas) and the
 * driver's last known position. Never phone numbers, customer identity, PIN or fares.
 */
class TripShareController extends Controller
{
    /** Driver positions older than this are flagged as stale in the UI. */
    private const STALE_AFTER_SECONDS = 120;

    public function __construct(private readonly DriverPresenceService $presence) {}

    public function show(string $token): Response
    {
        $trip = $this->resolve($token);

        if ($trip === null) {
            return response()->view('website.trip.expired', [], 410)
                ->header('X-Robots-Tag', 'noindex, nofollow')
                ->header('Cache-Control', 'no-store, private');
        }

        return response()->view('website.trip.share', [
            'token' => $token,
            'initial' => $this->payload($trip),
        ])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }

    public function status(string $token): JsonResponse
    {
        $trip = $this->resolve($token);

        if ($trip === null) {
            return response()->json([
                'active' => false,
                'expired' => true,
                'message' => 'This trip link has expired.',
            ], 410)->header('Cache-Control', 'no-store, private')
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return response()->json($this->payload($trip))
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function resolve(string $token): ?Trip
    {
        if (strlen($token) < 16) {
            return null;
        }

        $trip = Trip::query()
            ->with(['driver:id,name', 'vehicle', 'rideRequest', 'vehicleCategory:id,name'])
            ->where('share_token', $token)
            ->first();

        if ($trip === null
            || $trip->share_expires_at === null
            || $trip->share_expires_at->isPast()
            || ! ($trip->status instanceof TripStatus && ($trip->status->isActive() || $trip->status === TripStatus::SafetyHold))) {
            return null;
        }

        return $trip;
    }

    /** @return array<string, mixed> */
    private function payload(Trip $trip): array
    {
        $ride = $trip->rideRequest;
        $loc = $this->presence->get((int) $trip->driver_id);
        $updatedAt = $loc && ! empty($loc['ts']) ? Carbon::createFromTimestamp((int) $loc['ts']) : null;

        return [
            'active' => true,
            'status' => $trip->status->value,
            'status_label' => $this->statusLabel($trip->status),
            'status_message' => $this->statusMessage($trip->status),
            'driver' => [
                'first_name' => $trip->driver?->firstName() ?? 'Your driver',
            ],
            'vehicle' => [
                'label' => $trip->vehicle?->label() ?: null,
                'plate_number' => $trip->vehicle?->plate_number,
                'category' => $trip->vehicleCategory?->name,
            ],
            'pickup' => $ride ? [
                'address' => $ride->pickup_address,
                'lat' => round((float) $ride->pickup_lat, 3),
                'lng' => round((float) $ride->pickup_lng, 3),
            ] : null,
            'dropoff' => $ride ? [
                'address' => $ride->dropoff_address,
                'lat' => round((float) $ride->dropoff_lat, 3),
                'lng' => round((float) $ride->dropoff_lng, 3),
            ] : null,
            'driver_location' => $loc ? [
                'lat' => round((float) $loc['lat'], 5),
                'lng' => round((float) $loc['lng'], 5),
                'heading' => isset($loc['heading']) && $loc['heading'] !== null ? (float) $loc['heading'] : null,
                'updated_at' => $updatedAt?->toIso8601String(),
                'stale' => $updatedAt === null || $updatedAt->lt(now()->subSeconds(self::STALE_AFTER_SECONDS)),
            ] : null,
            'started_at' => $trip->started_at?->toIso8601String(),
            'share_expires_at' => $trip->share_expires_at?->toIso8601String(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    private function statusLabel(TripStatus $status): string
    {
        return match ($status) {
            TripStatus::DriverAssigned => 'Driver assigned',
            TripStatus::DriverEnRoute => 'On the way to pickup',
            TripStatus::Arrived => 'Driver at pickup',
            TripStatus::InProgress => 'Trip in progress',
            default => $status->label(),
        };
    }

    private function statusMessage(TripStatus $status): string
    {
        return match ($status) {
            TripStatus::DriverAssigned => 'A verified FemaleRider driver has accepted this ride.',
            TripStatus::DriverEnRoute => 'The driver is heading to the pickup point.',
            TripStatus::Arrived => 'The driver has arrived at the pickup point.',
            TripStatus::InProgress => 'The ride is under way to the destination.',
            default => 'Trip status: '.$status->label(),
        };
    }
}
