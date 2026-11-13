<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EligibilityStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Auth\OtpService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public static function normalizePhone(string $countryCode, string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $cc = preg_replace('/\D+/', '', $countryCode);
        $digits = ltrim($digits, '0');
        if (str_starts_with($digits, $cc) && strlen($digits) > 10) {
            $digits = substr($digits, strlen($cc));
        }

        return '+'.$cc.$digits;
    }

    /** POST /auth/otp/request */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'regex:/^\+?\d{1,4}$/'],
            'phone' => ['required', 'string', 'regex:/^[\d\s\-]{6,15}$/'],
            'device_id' => ['nullable', 'string', 'max:120'],
        ]);
        $phone = self::normalizePhone($data['country_code'], $data['phone']);
        $result = $this->otp->issue($phone, 'login', $request->ip(), $data['device_id'] ?? null);

        return ApiResponse::ok(array_filter([
            'phone' => $phone,
            'expires_in' => $result['expires_in'],
            'resend_in' => $result['resend_in'],
            'debug_code' => $result['debug_code'],
        ], fn ($v) => $v !== null), 'Verification code sent.');
    }

    /** POST /auth/otp/verify */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_code' => ['required', 'string', 'regex:/^\+?\d{1,4}$/'],
            'phone' => ['required', 'string', 'regex:/^[\d\s\-]{6,15}$/'],
            'code' => ['required', 'string', 'digits:'.config('femalerider.otp.length')],
            'device_name' => ['nullable', 'string', 'max:120'],
            'accept_terms' => ['sometimes', 'boolean'],
        ]);
        $phone = self::normalizePhone($data['country_code'], $data['phone']);
        $this->otp->verify($phone, $data['code']);

        [$user, $isNew] = DB::transaction(function () use ($phone, $data, $request) {
            $user = User::withTrashed()->where('phone', $phone)->first();
            $isNew = false;
            if (! $user) {
                $user = new User(['phone' => $phone, 'country_code' => '+'.ltrim($data['country_code'], '+')]);
                $user->forceFill(['eligibility_status' => EligibilityStatus::Draft, 'phone_verified_at' => now()])->save();
                UserRole::query()->create(['user_id' => $user->id, 'role' => 'customer', 'status' => 'pending']);
                foreach (['terms', 'privacy'] as $type) {
                    $user->consents()->create(['type' => $type, 'version' => config("femalerider.consent_versions.{$type}"), 'ip_address' => $request->ip(), 'accepted_at' => now()]);
                }
                $isNew = true;
            }

            return [$user, $isNew];
        });

        if ($user->trashed() || $user->account_status === 'deleted') {
            return ApiResponse::error('This account has been deleted.', 'account_deleted', 403);
        }

        $user->forceFill(['last_login_at' => now(), 'phone_verified_at' => $user->phone_verified_at ?? now()])->save();
        if ($user->account_status === 'pending_deletion') {
            // Logging back in cancels a pending deletion request.
            $user->forceFill(['account_status' => 'active', 'deletion_requested_at' => null])->save();
        }

        $token = $user->createToken(substr($data['device_name'] ?? $request->userAgent() ?? 'mobile', 0, 120), ['*']);
        AuditLog::record('auth.login', $user, [], [], ['new_account' => $isNew], $user);

        return ApiResponse::ok([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'is_new_user' => $isNew,
            'user' => new UserResource($user->fresh()),
        ], 'Signed in.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        if ($deviceToken = $request->input('device_token')) {
            $request->user()->deviceTokens()->where('token_hash', hash('sha256', $deviceToken))->update(['revoked_at' => now()]);
        }

        return ApiResponse::ok(null, 'Signed out.');
    }

    public function sessions(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken()?->id;

        return ApiResponse::ok($request->user()->tokens()->latest('last_used_at')->get()->map(fn ($t) => [
            'id' => $t->id,
            'device' => $t->name,
            'current' => $t->id === $current,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ]));
    }

    public function revokeSession(Request $request, int $id): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($id)->delete();

        return $deleted ? ApiResponse::ok(null, 'Session revoked.') : ApiResponse::error('Session not found.', 'not_found', 404);
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $request->user()->tokens()->where('id', '!=', $request->user()->currentAccessToken()?->id)->delete();

        return ApiResponse::ok(null, 'Other sessions signed out.');
    }
}
