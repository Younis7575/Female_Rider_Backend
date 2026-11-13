<?php

namespace App\Services\Driver;

use App\Enums\DriverStatus;
use App\Enums\EligibilityStatus;
use App\Events\RealtimeEvent;
use App\Exceptions\BusinessRuleException;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\DriverDocument;
use App\Models\DriverOnlineSession;
use App\Models\DriverProfile;
use App\Models\ServiceZone;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserRole;
use App\Models\Vehicle;
use App\Services\Dispatch\DispatchService;
use App\Services\Dispatch\DriverPresenceService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Driver application, documents, vehicle, approval workflow, availability and role switching.
 */
class DriverOnboardingService
{
    public function __construct(private DriverPresenceService $presence, private NotificationService $notifications) {}

    public function profileFor(User $user): DriverProfile
    {
        $profile = $user->driverProfile()->first()
            ?? DriverProfile::query()->forceCreate(['user_id' => $user->id, 'status' => DriverStatus::NotSubmitted->value]);
        $user->setRelation('driverProfile', $profile);

        return $profile;
    }

    public function saveProfile(User $user, array $data): DriverProfile
    {
        if ($user->eligibility_status !== EligibilityStatus::Approved) {
            throw new BusinessRuleException('Complete account verification before applying to drive.', 'eligibility_required', 403);
        }
        $profile = $this->profileFor($user);
        $this->assertEditable($profile);
        $profile->fill($data)->save();

        return $profile->fresh();
    }

    public function saveVehicle(User $user, array $data): Vehicle
    {
        $profile = $this->profileFor($user);
        $this->assertEditable($profile);

        $vehicle = $profile->primaryVehicle;
        $data['plate_number'] = strtoupper(preg_replace('/\s+/', '-', trim($data['plate_number'])));
        if (Vehicle::query()->where('plate_number', $data['plate_number'])->when($vehicle, fn ($q) => $q->where('id', '!=', $vehicle->id))->exists()) {
            throw new BusinessRuleException('This plate number is already registered.', 'plate_taken');
        }

        if ($vehicle) {
            $vehicle->update(array_merge($data, ['status' => 'pending', 'rejection_reason' => null]));
        } else {
            $vehicle = $profile->vehicles()->create(array_merge($data, ['status' => 'pending', 'is_primary' => true]));
        }

        return $vehicle->fresh('category');
    }

    public function uploadDocument(User $user, string $type, UploadedFile $file, ?string $expiry = null): DriverDocument
    {
        $profile = $this->profileFor($user);
        if (! in_array($type, array_merge(DriverProfile::REQUIRED_DOCUMENTS, DriverProfile::OPTIONAL_DOCUMENTS), true)) {
            throw new BusinessRuleException('Unknown document type.', 'validation');
        }
        if (in_array($profile->status, [DriverStatus::Suspended], true)) {
            throw new BusinessRuleException('Your driver account is suspended.', 'driver_suspended', 403);
        }

        $doc = $profile->documents()->create([
            'vehicle_id' => str_starts_with($type, 'vehicle_') || in_array($type, ['insurance', 'fitness_certificate']) ? $profile->primaryVehicle?->id : null,
            'type' => $type,
            'file_path' => $file->store("driver-documents/{$user->uuid}", 'local'),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'expiry_date' => $expiry,
            'status' => 'pending',
        ]);

        // Re-uploading a document on an approved account puts it back into review only for that document.
        return $doc;
    }

    public function submit(User $user, ?string $ip = null): DriverProfile
    {
        $profile = $this->profileFor($user)->load('documents', 'primaryVehicle');
        $this->assertEditable($profile);

        $missing = [];
        foreach (['full_name', 'cnic_number', 'date_of_birth', 'license_number', 'license_expiry', 'emergency_contact_name', 'emergency_contact_phone'] as $f) {
            if (blank($profile->{$f})) {
                $missing[] = str_replace('_', ' ', $f);
            }
        }
        if (! $profile->primaryVehicle) {
            $missing[] = 'vehicle details';
        }
        if (blank($profile->payout_method) || blank($profile->payout_account_number)) {
            $missing[] = 'payout account';
        }
        $uploaded = $profile->documents->whereNotIn('status', ['rejected'])->pluck('type')->unique()->all();
        foreach (DriverProfile::REQUIRED_DOCUMENTS as $type) {
            if (! in_array($type, $uploaded, true)) {
                $missing[] = str_replace('_', ' ', $type);
            }
        }
        if ($profile->date_of_birth && $profile->date_of_birth->age < (int) SystemSetting::get('driver.min_age', 18)) {
            throw new BusinessRuleException('You must be at least '.SystemSetting::get('driver.min_age', 18).' years old to drive.', 'underage');
        }
        if ($profile->license_expiry && $profile->license_expiry->isPast()) {
            throw new BusinessRuleException('Your driving licence has expired.', 'license_expired');
        }
        if ($missing) {
            throw new BusinessRuleException('Please complete: '.implode(', ', $missing).'.', 'application_incomplete', 422, ['missing' => $missing]);
        }

        DB::transaction(function () use ($profile, $user, $ip) {
            $profile->forceFill([
                'status' => DriverStatus::Pending,
                'submitted_at' => now(),
                'agreement_version' => config('femalerider.consent_versions.driver_agreement'),
                'agreement_accepted_at' => now(),
                'user_facing_reason' => null,
            ])->save();
            Consent::query()->create(['user_id' => $user->id, 'type' => 'driver_agreement', 'version' => config('femalerider.consent_versions.driver_agreement'), 'ip_address' => $ip, 'accepted_at' => now()]);
            UserRole::query()->updateOrCreate(['user_id' => $user->id, 'role' => 'driver'], ['status' => 'pending']);
            AuditLog::record('driver.application_submitted', $profile, [], [], [], $user);
        });

        return $profile->fresh();
    }

    // ---- Admin review ---------------------------------------------------

    public function setStatus(AdminUser $admin, DriverProfile $profile, DriverStatus $status, ?string $userReason = null, ?string $notes = null): DriverProfile
    {
        if ($status === DriverStatus::Approved) {
            $profile->load('documents', 'primaryVehicle');
            $docs = $profile->latestDocuments()->keyBy('type');
            $notApproved = collect(DriverProfile::REQUIRED_DOCUMENTS)->reject(fn ($t) => $docs->get($t)?->status === 'approved')->values();
            if ($notApproved->isNotEmpty()) {
                throw new BusinessRuleException('Approve all required documents first: '.$notApproved->implode(', '), 'documents_pending');
            }
            if ($profile->primaryVehicle?->status !== 'approved') {
                throw new BusinessRuleException('Approve the vehicle first.', 'vehicle_pending');
            }
            if ($profile->user->eligibility_status !== EligibilityStatus::Approved) {
                throw new BusinessRuleException('The account eligibility verification is not approved.', 'eligibility_required');
            }
        }

        $before = $profile->status->value;
        DB::transaction(function () use ($admin, $profile, $status, $userReason, $notes, $before) {
            $profile->forceFill([
                'status' => $status,
                'reviewer_id' => $admin->id,
                'reviewed_at' => now(),
                'review_notes' => $notes ?? $profile->review_notes,
                'user_facing_reason' => $userReason,
                'approved_at' => $status === DriverStatus::Approved ? now() : $profile->approved_at,
            ])->save();

            $roleStatus = match ($status) {
                DriverStatus::Approved => 'active',
                DriverStatus::Suspended, DriverStatus::Expired => 'suspended',
                DriverStatus::Rejected => 'revoked',
                default => 'pending',
            };
            UserRole::query()->updateOrCreate(['user_id' => $profile->user_id, 'role' => 'driver'], [
                'status' => $roleStatus,
                'approved_at' => $status === DriverStatus::Approved ? now() : null,
                'approved_by' => $status === DriverStatus::Approved ? $admin->id : null,
            ]);
            AuditLog::record('driver.'.$status->value, $profile, ['status' => $before], ['status' => $status->value], ['reason' => $userReason]);
        });

        if ($status !== DriverStatus::Approved && $profile->is_online) {
            $this->forceOffline($profile, 'status_'.$status->value);
        }

        $copy = [
            DriverStatus::Approved->value => ['You are approved to drive!', 'Switch to Driver mode and go online to start earning.'],
            DriverStatus::Rejected->value => ['Driver application update', $userReason ?? 'Your application was not approved.'],
            DriverStatus::MoreInfoRequired->value => ['Action needed on your application', $userReason ?? 'Please update your documents.'],
            DriverStatus::Suspended->value => ['Driver account suspended', $userReason ?? 'Please contact support.'],
            DriverStatus::UnderReview->value => ['Application under review', 'Our team is reviewing your documents.'],
            DriverStatus::Expired->value => ['Documents expired', $userReason ?? 'Please upload renewed documents.'],
        ];
        if (isset($copy[$status->value])) {
            $this->notifications->send($profile->user, 'driver.status', $copy[$status->value][0], $copy[$status->value][1], ['screen' => 'driver_application', 'status' => $status->value]);
        }

        return $profile->fresh();
    }

    public function reviewDocument(AdminUser $admin, DriverDocument $doc, bool $approve, ?string $reason = null): DriverDocument
    {
        $doc->update([
            'status' => $approve ? 'approved' : 'rejected',
            'reviewer_id' => $admin->id,
            'reviewed_at' => now(),
            'rejection_reason' => $approve ? null : $reason,
        ]);
        AuditLog::record($approve ? 'driver_document.approved' : 'driver_document.rejected', $doc, [], [], ['type' => $doc->type, 'reason' => $reason]);

        return $doc;
    }

    public function reviewVehicle(AdminUser $admin, Vehicle $vehicle, bool $approve, ?string $reason = null): Vehicle
    {
        $vehicle->update(['status' => $approve ? 'approved' : 'rejected', 'rejection_reason' => $approve ? null : $reason]);
        AuditLog::record($approve ? 'vehicle.approved' : 'vehicle.rejected', $vehicle, [], [], ['reason' => $reason]);

        return $vehicle;
    }

    // ---- Availability ---------------------------------------------------

    public function goOnline(User $user, float $lat, float $lng): DriverProfile
    {
        $user->loadMissing('roles', 'driverProfile.documents', 'driverProfile.primaryVehicle');
        $profile = $user->driverProfile;
        if (! $profile || ! $user->isApprovedDriver()) {
            throw new BusinessRuleException('Your driver account is not approved yet.', 'driver_not_approved', 403);
        }
        if ($issues = $profile->complianceIssues()) {
            throw new BusinessRuleException($issues[0], 'driver_not_compliant', 403, ['issues' => $issues]);
        }
        if ($user->active_mode !== 'driver') {
            throw new BusinessRuleException('Switch to Driver mode first.', 'wrong_mode', 409);
        }
        if ($user->activeTrip() && $user->activeTrip()->customer_id === $user->id) {
            throw new BusinessRuleException('You have an active trip as a customer.', 'active_trip_exists', 409);
        }
        $zone = ServiceZone::locate($lat, $lng);
        if (! $zone) {
            throw new BusinessRuleException('You are outside an active service area.', 'outside_service_area');
        }

        if (! $profile->is_online) {
            $profile->forceFill(['is_online' => true, 'last_online_at' => now(), 'service_zone_id' => $zone->id])->save();
            DriverOnlineSession::query()->create(['driver_profile_id' => $profile->id, 'online_at' => now()]);
        }
        $this->presence->update($profile, $lat, $lng, ['force_snapshot' => true]);
        if ($user->activeTrip()) {
            $this->presence->markBusy($user->id);
        }
        RealtimeEvent::send(['admin.operations'], 'driver.online', ['driver_id' => $user->id]);
        if (! $user->activeTrip()) {
            app(DispatchService::class)->discoverForDriver($user->fresh(['driverProfile.primaryVehicle', 'driverProfile.documents', 'roles']), true);
        }

        return $profile->fresh();
    }

    public function goOffline(User $user): DriverProfile
    {
        $profile = $user->driverProfile ?? throw new BusinessRuleException('Driver profile not found.', 'not_found', 404);
        $this->forceOffline($profile, 'driver_request');

        return $profile->fresh();
    }

    public function forceOffline(DriverProfile $profile, string $reason): void
    {
        $profile->forceFill(['is_online' => false])->save();
        DriverOnlineSession::query()->where('driver_profile_id', $profile->id)->whereNull('offline_at')->update(['offline_at' => now(), 'ended_reason' => $reason]);
        $this->presence->goOffline($profile);
        RealtimeEvent::send(['admin.operations'], 'driver.offline', ['driver_id' => $profile->user_id]);
    }

    /** Customer ↔ Driver mode switch, enforcing all role-switching rules server-side. */
    public function switchMode(User $user, string $mode): User
    {
        $user->loadMissing('roles', 'driverProfile.documents', 'driverProfile.primaryVehicle');
        if ($user->active_mode === $mode) {
            return $user;
        }
        if ($user->activeTrip()) {
            throw new BusinessRuleException('You cannot switch modes during an active trip.', 'active_trip_exists', 409);
        }
        if ($mode === 'driver') {
            if (! $user->isApprovedDriver()) {
                throw new BusinessRuleException('Driver mode is available after your driver application is approved.', 'driver_not_approved', 403, ['driver_status' => $user->driverProfile?->status?->value ?? 'not_submitted']);
            }
            $openRequest = $user->rideRequests()->whereIn('status', ['searching', 'offers_available'])->where('expires_at', '>', now())->exists();
            if ($openRequest) {
                throw new BusinessRuleException('Cancel your open ride request before switching to Driver mode.', 'open_request_exists', 409);
            }
        } else {
            if ($user->driverProfile?->is_online) {
                throw new BusinessRuleException('Go offline before switching to Customer mode.', 'driver_online', 409);
            }
            if (! $user->canUseCustomerMode()) {
                throw new BusinessRuleException('Customer mode requires an approved verification.', 'customer_not_eligible', 403);
            }
        }
        $user->forceFill(['active_mode' => $mode])->save();
        AuditLog::record('user.mode_switched', $user, [], ['mode' => $mode], [], $user);

        return $user;
    }

    private function assertEditable(DriverProfile $profile): void
    {
        if (! $profile->status->canEditApplication()) {
            throw new BusinessRuleException('Your application is '.$profile->status->label().' and cannot be edited right now.', 'application_locked', 409);
        }
    }
}
