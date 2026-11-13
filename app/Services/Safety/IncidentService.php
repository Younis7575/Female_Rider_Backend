<?php

namespace App\Services\Safety;

use App\Enums\TripStatus;
use App\Events\RealtimeEvent;
use App\Exceptions\BusinessRuleException;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Trip;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Sms\SmsSender;
use App\Services\Trips\TripService;
use Illuminate\Http\UploadedFile;

/** SOS and safety incident handling with restricted evidence and audit trail. */
class IncidentService
{
    public function __construct(private NotificationService $notifications, private SmsSender $sms) {}

    public function sos(User $user, ?Trip $trip, ?float $lat, ?float $lng, ?string $note = null): Incident
    {
        if ($trip && ! $trip->involves($user)) {
            throw new BusinessRuleException('Trip not found.', 'not_found', 404);
        }
        $incident = Incident::query()->create([
            'trip_id' => $trip?->id,
            'reporter_id' => $user->id,
            'against_user_id' => $trip ? ($trip->customer_id === $user->id ? $trip->driver_id : $trip->customer_id) : null,
            'type' => 'sos',
            'severity' => 'critical',
            'status' => 'open',
            'description' => $note,
            'lat' => $lat,
            'lng' => $lng,
        ]);

        RealtimeEvent::send(['admin.operations'], 'safety.sos', [
            'incident_id' => $incident->id,
            'reference' => $incident->reference,
            'trip_id' => $trip?->uuid,
            'lat' => $lat, 'lng' => $lng,
            'reporter' => $user->displayName(),
        ]);

        // Trusted-contact SMS alert with minimal information.
        if ($user->emergency_contact_phone) {
            $mapLink = $lat && $lng ? " https://maps.google.com/?q={$lat},{$lng}" : '';
            $this->sms->send($user->emergency_contact_phone, "FemaleRider SOS: {$user->firstName()} triggered an emergency alert.{$mapLink} Ref {$incident->reference}");
        }

        $this->notifications->send($user, 'safety.sos', 'SOS alert sent',
            'Our safety team has been alerted. If you are in immediate danger call '.($trip?->serviceZone?->city?->emergency_number ?? '15').'.', ['incident_id' => $incident->id]);

        AuditLog::record('safety.sos_raised', $incident, [], [], ['trip_id' => $trip?->id], $user);

        return $incident;
    }

    public function report(User $user, array $data, array $files = []): Incident
    {
        $trip = null;
        if (! empty($data['trip_id'])) {
            $trip = Trip::query()->where('uuid', $data['trip_id'])->first();
            if (! $trip || ! $trip->involves($user)) {
                throw new BusinessRuleException('Trip not found.', 'not_found', 404);
            }
        }
        $incident = Incident::query()->create([
            'trip_id' => $trip?->id,
            'reporter_id' => $user->id,
            'against_user_id' => $trip ? ($trip->customer_id === $user->id ? $trip->driver_id : $trip->customer_id) : null,
            'type' => $data['type'],
            'severity' => $data['severity'] ?? 'medium',
            'status' => 'open',
            'description' => $data['description'],
        ]);
        /** @var UploadedFile $file */
        foreach ($files as $file) {
            $incident->evidence()->create([
                'uploaded_by' => $user->id,
                'file_path' => $file->store("incident-evidence/{$incident->uuid}", 'local'),
                'mime' => $file->getMimeType(),
            ]);
        }
        if (in_array($incident->severity, ['high', 'critical'], true)) {
            RealtimeEvent::send(['admin.operations'], 'safety.incident', ['incident_id' => $incident->id, 'reference' => $incident->reference, 'severity' => $incident->severity]);
        }

        return $incident;
    }

    public function updateStatus(AdminUser $admin, Incident $incident, string $status, ?string $resolution = null): Incident
    {
        $before = $incident->status;
        $attrs = ['status' => $status, 'assigned_admin_id' => $incident->assigned_admin_id ?? $admin->id];
        if ($status === 'acknowledged' && ! $incident->acknowledged_at) {
            $attrs['acknowledged_at'] = now();
        }
        if (in_array($status, ['resolved', 'closed'], true)) {
            $attrs['resolved_at'] = now();
            $attrs['resolution'] = $resolution;
        }
        $incident->update($attrs);
        AuditLog::record('incident.'.$status, $incident, ['status' => $before], ['status' => $status]);

        if ($status === 'acknowledged' && $incident->type === 'sos') {
            $this->notifications->send($incident->reporter, 'safety.update', 'Safety team responding', 'Your SOS ('.$incident->reference.') has been acknowledged.', ['incident_id' => $incident->id]);
        }

        return $incident;
    }

    /** Place an active trip on safety hold (admin only). */
    public function safetyHold(AdminUser $admin, Trip $trip): void
    {
        if (! $trip->status->canTransitionTo(TripStatus::SafetyHold)) {
            throw new BusinessRuleException('Trip cannot be placed on hold in its current state.', 'invalid_transition', 409);
        }
        app(TripService::class)->transition($trip, TripStatus::SafetyHold, 'admin', $admin->id);
        AuditLog::record('trip.safety_hold', $trip);
    }
}
