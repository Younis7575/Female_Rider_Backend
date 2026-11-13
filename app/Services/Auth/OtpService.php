<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessRuleException;
use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Phone OTP issuance and verification with hashing, expiry, resend cooldown,
 * per-phone hourly caps and attempt limits.
 */
class OtpService
{
    public function __construct(private SmsSender $sms) {}

    /** @return array{expires_in:int, resend_in:int, debug_code:?string} */
    public function issue(string $phone, string $purpose = 'login', ?string $ip = null, ?string $deviceId = null): array
    {
        $cfg = config('femalerider.otp');

        $latest = OtpCode::query()->where('phone', $phone)->where('purpose', $purpose)->latest('id')->first();
        if ($latest && $latest->created_at->diffInSeconds(now()) < $cfg['resend_cooldown_seconds']) {
            $wait = $cfg['resend_cooldown_seconds'] - (int) $latest->created_at->diffInSeconds(now());
            throw new BusinessRuleException("Please wait {$wait}s before requesting another code.", 'otp_cooldown', 429, ['retry_after' => $wait]);
        }

        $hourKey = 'otp-hour:'.$phone;
        if (RateLimiter::tooManyAttempts($hourKey, $cfg['max_requests_per_hour'])) {
            throw new BusinessRuleException('Too many code requests. Try again later.', 'otp_rate_limited', 429, ['retry_after' => RateLimiter::availableIn($hourKey)]);
        }
        RateLimiter::hit($hourKey, 3600);

        $isTestNumber = $this->isTestNumber($phone);
        $code = $isTestNumber ? (string) $cfg['test_code'] : $this->generate($cfg['length']);

        OtpCode::query()->where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        OtpCode::query()->create([
            'phone' => $phone,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'ip_address' => $ip,
            'device_id' => $deviceId,
            'expires_at' => now()->addSeconds($cfg['ttl_seconds']),
        ]);

        if (! $isTestNumber) {
            $this->sms->send($phone, "Your FemaleRider verification code is {$code}. It expires in ".intdiv($cfg['ttl_seconds'], 60).' minutes. Never share this code.');
        }

        $expose = $cfg['expose_in_response'] && app()->environment(['local', 'testing']);

        return [
            'expires_in' => $cfg['ttl_seconds'],
            'resend_in' => $cfg['resend_cooldown_seconds'],
            'debug_code' => $expose ? $code : null,
        ];
    }

    /** QA numbers with a fixed code (config otp.test_numbers / otp.test_code). */
    public function isTestNumber(string $phone): bool
    {
        $code = (string) config('femalerider.otp.test_code');

        return preg_match('/^\d{'.(int) config('femalerider.otp.length').'}$/', $code) === 1
            && in_array($phone, config('femalerider.otp.test_numbers', []), true);
    }

    public function verify(string $phone, string $code, string $purpose = 'login'): void
    {
        $otp = OtpCode::query()->where('phone', $phone)->where('purpose', $purpose)
            ->whereNull('consumed_at')->latest('id')->first();

        if (! $otp || $otp->expires_at->isPast()) {
            throw new BusinessRuleException('The code has expired. Please request a new one.', 'otp_expired', 422);
        }

        if ($otp->attempts >= config('femalerider.otp.max_attempts')) {
            $otp->update(['consumed_at' => now()]);
            throw new BusinessRuleException('Too many incorrect attempts. Please request a new code.', 'otp_locked', 429);
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            $left = config('femalerider.otp.max_attempts') - $otp->attempts;
            throw new BusinessRuleException("Incorrect code. {$left} attempt(s) left.", 'otp_invalid', 422, ['attempts_left' => $left]);
        }

        $otp->update(['consumed_at' => now()]);
    }

    private function generate(int $length): string
    {
        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
