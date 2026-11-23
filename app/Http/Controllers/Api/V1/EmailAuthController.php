<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EligibilityStatus;
use App\Exceptions\BusinessRuleException;
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
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Email + password accounts.
 *
 *  register  → email OTP (purpose "register") → verified, signed in, then she submits her
 *              details/ID and the account stays pending until an admin approves it.
 *  login     → email/phone + password → email OTP (purpose "login") → signed in.
 *  forgot    → email OTP (purpose "reset") → new password.
 */
class EmailAuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    /** POST /auth/register */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'country_code' => ['required', 'string', 'regex:/^\+?\d{1,4}$/'],
            'phone' => ['required', 'string', 'regex:/^[\d\s\-]{6,15}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'accept_terms' => ['accepted'],
        ]);
        $email = strtolower(trim($data['email']));
        $phone = AuthController::normalizePhone($data['country_code'], $data['phone']);

        $byEmail = User::withTrashed()->where('email', $email)->first();
        $byPhone = User::withTrashed()->where('phone', $phone)->first();
        if ($byEmail?->email_verified_at) {
            throw new BusinessRuleException('An account with this email already exists. Please log in.', 'email_taken', 422, ['field' => 'email']);
        }
        if ($byPhone && ($byPhone->email_verified_at || $byPhone->password === null) && $byPhone->id !== $byEmail?->id) {
            throw new BusinessRuleException('An account with this phone number already exists. Please log in.', 'phone_taken', 422, ['field' => 'phone']);
        }

        DB::transaction(function () use ($byEmail, $byPhone, $email, $phone, $data, $request) {
            // An unverified earlier attempt is simply overwritten.
            $user = $byEmail ?? $byPhone ?? new User;
            $isNew = ! $user->exists;
            $user->fill(['name' => $data['name'], 'email' => $email, 'phone' => $phone, 'country_code' => '+'.ltrim($data['country_code'], '+')]);
            $user->password = $data['password'];
            if ($isNew) {
                $user->forceFill(['eligibility_status' => EligibilityStatus::Draft]);
            }
            $user->save();
            if ($isNew) {
                UserRole::query()->create(['user_id' => $user->id, 'role' => 'customer', 'status' => 'pending']);
                foreach (['terms', 'privacy'] as $type) {
                    $user->consents()->create(['type' => $type, 'version' => config("femalerider.consent_versions.{$type}"), 'ip_address' => $request->ip(), 'accepted_at' => now()]);
                }
            }
        });

        $sent = $this->otp->issue($email, 'register', $request->ip());

        return ApiResponse::created($this->otpPayload($email, $sent), 'We sent a verification code to your email.');
    }

    /** POST /auth/register/verify */
    public function verifyRegistration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string', 'digits:'.config('femalerider.otp.length')],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);
        $email = strtolower(trim($data['email']));
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            throw new BusinessRuleException('No registration found for this email.', 'not_found', 404);
        }
        $this->otp->verify($email, $data['code'], 'register');
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        return $this->signIn($request, $user, $data['device_name'] ?? null, true);
    }

    /** POST /auth/login – step 1: check password, email a code. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string', 'max:191'],
        ]);
        $user = $this->findByLogin($data['login']);
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw new BusinessRuleException('Incorrect email/phone or password.', 'invalid_credentials', 422);
        }
        $this->assertCanSignIn($user);

        if (! $user->email_verified_at) {
            try {
                $sent = $this->otp->issue($user->email, 'register', $request->ip());
            } catch (BusinessRuleException $e) {
                if ($e->errorCode !== 'otp_cooldown') {
                    throw $e;
                }
                // A code was sent moments ago (e.g. right after registering) – just use it.
                $sent = ['expires_in' => config('femalerider.otp.ttl_seconds'), 'resend_in' => (int) ($e->context['retry_after'] ?? 60)];
            }

            return ApiResponse::ok($this->otpPayload($user->email, $sent) + ['purpose' => 'register'], 'Please verify your email first. We sent you a code.');
        }

        $sent = $this->otp->issue($user->email, 'login', $request->ip());

        return ApiResponse::ok($this->otpPayload($user->email, $sent) + ['purpose' => 'login'], 'We sent a login code to your email.');
    }

    /** POST /auth/login/verify – step 2. */
    public function verifyLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string', 'digits:'.config('femalerider.otp.length')],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);
        $email = strtolower(trim($data['email']));
        $user = User::withTrashed()->where('email', $email)->first();
        if (! $user || ! $user->email_verified_at) {
            throw new BusinessRuleException('The code has expired. Please request a new one.', 'otp_expired', 422);
        }
        $this->otp->verify($email, $data['code'], 'login');
        $this->assertCanSignIn($user);

        return $this->signIn($request, $user, $data['device_name'] ?? null, false);
    }

    /** POST /auth/otp/resend – resend a register/login/reset code. */
    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'purpose' => ['required', 'in:register,login,reset'],
        ]);
        $email = strtolower(trim($data['email']));
        $user = User::query()->where('email', $email)->first();
        $ok = $user && match ($data['purpose']) {
            'register' => ! $user->email_verified_at,
            default => (bool) $user->email_verified_at,
        };
        if (! $ok) {
            // Don't reveal whether the email exists.
            return ApiResponse::ok(['email' => $this->mask($email)], 'If the email is registered, a new code has been sent.');
        }
        $sent = $this->otp->issue($email, $data['purpose'], $request->ip());

        return ApiResponse::ok($this->otpPayload($email, $sent), 'A new code has been sent to your email.');
    }

    /** POST /auth/password/forgot */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email']]);
        $email = strtolower(trim($data['email']));
        $user = User::query()->where('email', $email)->whereNotNull('email_verified_at')->first();
        $sent = $user ? $this->otp->issue($email, 'reset', $request->ip()) : ['expires_in' => config('femalerider.otp.ttl_seconds'), 'resend_in' => config('femalerider.otp.resend_cooldown_seconds'), 'debug_code' => null];

        return ApiResponse::ok($this->otpPayload($email, $sent), 'If the email is registered, we sent a reset code.');
    }

    /** POST /auth/password/reset */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string', 'digits:'.config('femalerider.otp.length')],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        $email = strtolower(trim($data['email']));
        $user = User::query()->where('email', $email)->whereNotNull('email_verified_at')->first();
        if (! $user) {
            throw new BusinessRuleException('The code has expired. Please request a new one.', 'otp_expired', 422);
        }
        $this->otp->verify($email, $data['code'], 'reset');
        $user->password = $data['password'];
        $user->save();
        $user->tokens()->delete();
        AuditLog::record('auth.password_reset', $user, [], [], [], $user);

        return ApiResponse::ok(null, 'Password changed. Please log in.');
    }

    private function findByLogin(string $login): ?User
    {
        $login = trim($login);
        if (str_contains($login, '@')) {
            return User::withTrashed()->where('email', strtolower($login))->first();
        }
        $digits = preg_replace('/\D+/', '', $login);
        if (strlen($digits) < 6) {
            return null;
        }

        return User::withTrashed()->where('phone', AuthController::normalizePhone(config('femalerider.default_country_code'), $digits))->first();
    }

    private function assertCanSignIn(User $user): void
    {
        if ($user->trashed() || $user->account_status === 'deleted') {
            throw new BusinessRuleException('This account has been deleted.', 'account_deleted', 403);
        }
    }

    private function signIn(Request $request, User $user, ?string $device, bool $isNew): JsonResponse
    {
        $user->forceFill(['last_login_at' => now()])->save();
        if ($user->account_status === 'pending_deletion') {
            $user->forceFill(['account_status' => 'active', 'deletion_requested_at' => null])->save();
        }
        $token = $user->createToken(substr($device ?? $request->userAgent() ?? 'mobile', 0, 120), ['*']);
        AuditLog::record('auth.login', $user, [], [], ['method' => 'email_otp', 'new_account' => $isNew], $user);

        return ApiResponse::ok([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'is_new_user' => $isNew,
            'user' => new UserResource($user->fresh()),
        ], 'Signed in.');
    }

    private function otpPayload(string $email, array $sent): array
    {
        return array_filter([
            'email' => $email,
            'email_masked' => $this->mask($email),
            'expires_in' => $sent['expires_in'],
            'resend_in' => $sent['resend_in'],
            'debug_code' => $sent['debug_code'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('*', max(1, mb_strlen($local) - 2)).'@'.$domain;
    }
}
