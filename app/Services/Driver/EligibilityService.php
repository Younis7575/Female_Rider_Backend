<?php

namespace App\Services\Driver;

use App\Enums\EligibilityStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\EligibilityVerification;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Women-only eligibility workflow: configurable evidence + human review + appeals.
 * No automated gender inference is performed anywhere in the platform.
 */
class EligibilityService
{
    public function __construct(private NotificationService $notifications) {}

    public function submit(User $user, array $data, ?UploadedFile $front, ?UploadedFile $back, ?UploadedFile $selfie, ?string $ip = null): EligibilityVerification
    {
        $current = $user->eligibility_status;
        if (! $current->canSubmit()) {
            throw new BusinessRuleException('Your verification is already '.$current->label().'.', 'eligibility_locked', 409);
        }
        if (! $front) {
            throw new BusinessRuleException('Please upload the front of your identity document.', 'document_required');
        }
        if (config('femalerider.eligibility.require_selfie') && ! $selfie) {
            throw new BusinessRuleException('A profile verification photo is required.', 'selfie_required');
        }

        return DB::transaction(function () use ($user, $data, $front, $back, $selfie, $ip) {
            $dir = "eligibility/{$user->uuid}";
            $v = EligibilityVerification::query()->create([
                'user_id' => $user->id,
                'status' => EligibilityStatus::Submitted,
                'method' => 'document_review',
                'document_type' => $data['document_type'],
                'document_path' => $front->store($dir, 'local'),
                'document_back_path' => $back?->store($dir, 'local'),
                'selfie_path' => $selfie?->store($dir, 'local'),
                'declaration' => $data['declaration'] ?? null,
                'consent_version' => config('femalerider.eligibility.policy_version'),
                'consented_at' => now(),
                'submitted_at' => now(),
            ]);
            Consent::query()->create(['user_id' => $user->id, 'type' => 'eligibility', 'version' => config('femalerider.eligibility.policy_version'), 'ip_address' => $ip, 'accepted_at' => now()]);
            $user->forceFill(['eligibility_status' => EligibilityStatus::Submitted])->save();
            AuditLog::record('eligibility.submitted', $v, [], [], [], $user);

            return $v;
        });
    }

    public function appeal(User $user, string $reason): EligibilityVerification
    {
        if (! $user->eligibility_status->canAppeal()) {
            throw new BusinessRuleException('Appeals are only available for rejected or suspended verifications.', 'appeal_not_allowed', 409);
        }
        $v = $user->latestEligibility ?? throw new BusinessRuleException('No verification found.', 'not_found', 404);
        $v->update(['status' => EligibilityStatus::Appealed, 'appeal_reason' => $reason, 'appealed_at' => now()]);
        $user->forceFill(['eligibility_status' => EligibilityStatus::Appealed])->save();
        AuditLog::record('eligibility.appealed', $v, [], [], [], $user);

        return $v;
    }

    // ---- Admin review ---------------------------------------------------

    public function startReview(AdminUser $admin, EligibilityVerification $v): void
    {
        if (in_array($v->status, [EligibilityStatus::Submitted, EligibilityStatus::Appealed], true)) {
            $this->setStatus($admin, $v, EligibilityStatus::UnderReview, null, null, false);
        }
    }

    public function approve(AdminUser $admin, EligibilityVerification $v, ?string $notes = null): void
    {
        DB::transaction(function () use ($admin, $v, $notes) {
            $this->setStatus($admin, $v, EligibilityStatus::Approved, $notes, null);
            UserRole::query()->updateOrCreate(
                ['user_id' => $v->user_id, 'role' => 'customer'],
                ['status' => 'active', 'approved_at' => now(), 'approved_by' => $admin->id],
            );
        });
    }

    public function reject(AdminUser $admin, EligibilityVerification $v, string $userReason, ?string $notes = null): void
    {
        $this->setStatus($admin, $v, EligibilityStatus::Rejected, $notes, $userReason);
    }

    public function requestInfo(AdminUser $admin, EligibilityVerification $v, string $userReason, ?string $notes = null): void
    {
        $this->setStatus($admin, $v, EligibilityStatus::MoreInfoRequired, $notes, $userReason);
    }

    public function suspend(AdminUser $admin, User $user, string $userReason): void
    {
        $v = $user->latestEligibility;
        if ($v) {
            $this->setStatus($admin, $v, EligibilityStatus::Suspended, null, $userReason);
        }
        $user->roles()->where('role', 'customer')->update(['status' => 'suspended']);
    }

    private function setStatus(AdminUser $admin, EligibilityVerification $v, EligibilityStatus $status, ?string $notes, ?string $userReason, bool $notify = true): void
    {
        $before = $v->status->value;
        $v->update([
            'status' => $status,
            'reviewer_id' => $admin->id,
            'reviewed_at' => now(),
            'reviewer_notes' => $notes ?? $v->reviewer_notes,
            'user_facing_reason' => $userReason,
        ]);
        $v->user->forceFill(['eligibility_status' => $status])->save();
        AuditLog::record('eligibility.'.$status->value, $v, ['status' => $before], ['status' => $status->value], ['reason' => $userReason]);

        if ($notify) {
            $messages = [
                EligibilityStatus::Approved->value => ['You are verified!', 'Welcome to FemaleRider. You can now request rides.'],
                EligibilityStatus::Rejected->value => ['Verification update', 'We could not verify your account. You can appeal from the app.'],
                EligibilityStatus::MoreInfoRequired->value => ['More information needed', $userReason ?? 'Please update your verification details.'],
                EligibilityStatus::Suspended->value => ['Account suspended', $userReason ?? 'Please contact support.'],
            ];
            if (isset($messages[$status->value])) {
                [$title, $body] = $messages[$status->value];
                $this->notifications->send($v->user, 'account.verification', $title, $body, ['screen' => 'verification', 'status' => $status->value]);
            }
        }
    }
}
