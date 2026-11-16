<?php

namespace App\Services\Guardian;

use App\Jobs\SendGuardianAlert;
use App\Models\DriverProfile;
use App\Models\GuardianAlert;
use App\Models\Trip;
use App\Services\Dispatch\DriverPresenceService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Shares the driver's current location with her guardian on WhatsApp when she picks up a
 * rider (trip started with the PIN) and when she completes the ride. Only the driver's own
 * location, name, vehicle and time are sent — never the rider's identity or addresses.
 */
class GuardianAlertService
{
    public const EVENTS = ['trip_started', 'trip_completed'];

    public function __construct(private DriverPresenceService $presence, private WhatsAppService $whatsapp) {}

    public function queueForTrip(Trip $trip, string $event): ?GuardianAlert
    {
        $profile = $trip->driver?->driverProfile;
        if (! config('femalerider.guardian.enabled') || ! $profile || ! $profile->hasGuardian() || ! $profile->guardian_alerts_enabled) {
            return null;
        }

        [$lat, $lng, $source] = $this->location($trip, $event);
        try {
            $alert = GuardianAlert::query()->create([
                'driver_profile_id' => $profile->id, 'trip_id' => $trip->id, 'event' => $event,
                'lat' => $lat, 'lng' => $lng, 'location_source' => $source,
            ]);
        } catch (QueryException) {
            return null; // already queued for this trip/event
        }
        SendGuardianAlert::dispatch($alert->id)->onQueue('notifications');

        return $alert;
    }

    /** "Guardian added" message so the guardian knows why she will receive these alerts. */
    public function queueTest(DriverProfile $profile): GuardianAlert
    {
        $loc = $this->presence->get($profile->user_id);
        $alert = GuardianAlert::query()->create([
            'driver_profile_id' => $profile->id, 'event' => 'test',
            'lat' => $loc['lat'] ?? null, 'lng' => $loc['lng'] ?? null, 'location_source' => $loc ? 'live' : null,
        ]);
        SendGuardianAlert::dispatch($alert->id)->onQueue('notifications');

        return $alert;
    }

    /** @return array{0: ?float, 1: ?float, 2: ?string} */
    private function location(Trip $trip, string $event): array
    {
        $live = $this->presence->get($trip->driver_id);
        $maxAge = (int) config('femalerider.guardian.live_location_max_age_seconds', 300);
        if ($live && ($live['ts'] ?? 0) >= now()->subSeconds($maxAge)->timestamp) {
            return [(float) $live['lat'], (float) $live['lng'], 'live'];
        }
        $ride = $trip->rideRequest;

        return $event === 'trip_completed'
            ? [(float) $ride->dropoff_lat, (float) $ride->dropoff_lng, 'dropoff']
            : [(float) $ride->pickup_lat, (float) $ride->pickup_lng, 'pickup'];
    }

    /** Body parameters for each template — keep in sync with documentation/WHATSAPP_GUARDIAN_ALERTS.md. */
    public function templateParams(GuardianAlert $alert): array
    {
        $profile = $alert->driverProfile;
        $first = Str::of($profile->full_name ?: $profile->user?->name ?: 'Driver')->explode(' ')->first();
        $time = ($alert->created_at ?? now())->timezone('Asia/Karachi')->format('d M, h:i A');
        $map = $alert->mapsUrl() ?? 'location unavailable';
        $vehicle = $profile->primaryVehicle;
        $car = $vehicle ? trim("{$vehicle->color} {$vehicle->make} {$vehicle->model} ({$vehicle->plate_number})") : 'vehicle on file';

        return match ($alert->event) {
            'trip_started' => [$profile->guardian_name, $first, $car, $time, $map],
            'trip_completed' => [$profile->guardian_name, $first, $time, $map],
            default => [$profile->guardian_name, $first],
        };
    }

    public function templateName(string $event): string
    {
        return (string) config("services.whatsapp.templates.{$event}");
    }

    public function recipient(DriverProfile $profile): ?string
    {
        return WhatsAppService::normalize($profile->guardian_whatsapp);
    }
}
