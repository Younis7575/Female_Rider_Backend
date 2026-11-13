<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The authenticated user's own account view. */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $u = $this->resource;
        $u->loadMissing('roles', 'driverProfile', 'latestEligibility');
        $driver = $u->driverProfile;

        return [
            'id' => $u->uuid,
            'phone' => $u->phone,
            'country_code' => $u->country_code,
            'name' => $u->name,
            'email' => $u->email,
            'photo_url' => $u->photoUrl(),
            'locale' => $u->locale,
            'account_status' => $u->account_status,
            'active_mode' => $u->active_mode,
            'rating' => (float) $u->rating_avg,
            'rating_count' => $u->rating_count,
            'profile_completed' => (bool) $u->profile_completed_at,
            'emergency_contact' => [
                'name' => $u->emergency_contact_name,
                'phone' => $u->emergency_contact_phone,
            ],
            'notification_preferences' => $u->notification_preferences ?? ['push' => true, 'promotions' => true, 'payment' => true],
            'privacy_preferences' => $u->privacy_preferences ?? ['share_trip_with_emergency_contact' => false],
            'eligibility' => [
                'status' => $u->eligibility_status->value,
                'label' => $u->eligibility_status->label(),
                'reason' => $u->latestEligibility?->user_facing_reason,
                'can_submit' => $u->eligibility_status->canSubmit(),
                'can_appeal' => $u->eligibility_status->canAppeal(),
            ],
            'roles' => $u->roles->map(fn ($r) => ['role' => $r->role, 'status' => $r->status])->values(),
            'capabilities' => [
                'customer_mode' => $u->canUseCustomerMode(),
                'driver_mode' => $u->isApprovedDriver(),
            ],
            'driver' => [
                'status' => $driver?->status?->value ?? 'not_submitted',
                'is_online' => (bool) $driver?->is_online,
                'reason' => $driver?->user_facing_reason,
            ],
            'realtime_channels' => [
                'user' => 'private-user.'.$u->id,
                'driver' => $driver ? 'private-driver.'.$u->id : null,
            ],
            'deletion_requested_at' => $u->deletion_requested_at?->toIso8601String(),
            'created_at' => $u->created_at?->toIso8601String(),
        ];
    }
}
