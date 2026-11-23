<?php

namespace Tests\Feature\Api;

use App\Enums\EligibilityStatus;
use App\Mail\OtpCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class EmailAuthTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    private ?string $lastCode = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
        Mail::fake();
    }

    private function code(string $email, string $purpose): string
    {
        $code = null;
        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $m) use ($email, $purpose, &$code) {
            if ($m->hasTo($email) && $m->purpose === $purpose) {
                $code = $m->code;
            }

            return true;
        });
        $this->assertNotNull($code, "No {$purpose} email to {$email}");

        return $code;
    }

    private function register(array $over = [])
    {
        return $this->postJson('/api/v1/auth/register', array_merge([
            'name' => 'Ayesha Khan', 'email' => 'Ayesha@Example.com', 'country_code' => '+92', 'phone' => '0300 5551234',
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'accept_terms' => true,
        ], $over));
    }

    public function test_register_verify_email_then_account_is_pending_until_approved(): void
    {
        $this->register()->assertCreated()->assertJsonPath('data.email_masked', 'ay****@example.com');
        $user = User::query()->where('email', 'ayesha@example.com')->first();
        $this->assertNull($user->email_verified_at);
        $this->assertSame('+923005551234', $user->phone);

        // Can't log in before verifying: a register code is re-sent instead.
        $this->postJson('/api/v1/auth/login', ['login' => 'ayesha@example.com', 'password' => 'secret123'])->assertOk()->assertJsonPath('data.purpose', 'register');

        $this->travel(2)->minutes();
        $this->postJson('/api/v1/auth/email-otp/resend', ['email' => 'ayesha@example.com', 'purpose' => 'register'])->assertOk();
        $code = $this->code('ayesha@example.com', 'register');
        $res = $this->postJson('/api/v1/auth/register/verify', ['email' => 'ayesha@example.com', 'code' => $code])->assertOk()->assertJsonPath('data.is_new_user', true);
        $this->assertNotEmpty($res->json('data.token'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame(EligibilityStatus::Draft, $user->fresh()->eligibility_status); // must submit details → admin approval

        $this->register(['phone' => '03009998888'])->assertStatus(422)->assertJsonPath('error.code', 'email_taken');
        $this->register(['email' => 'other@example.com'])->assertStatus(422)->assertJsonPath('error.code', 'phone_taken');
    }

    public function test_registration_validation(): void
    {
        $this->register(['password_confirmation' => 'nope12345'])->assertStatus(422);
        $this->register(['password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->register(['email' => 'not-an-email'])->assertStatus(422);
        $this->register(['accept_terms' => false])->assertStatus(422);
    }

    public function test_login_always_needs_password_and_email_code(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['email' => 'sara@example.com', 'password' => 'secret123', 'email_verified_at' => now()])->save();

        $this->postJson('/api/v1/auth/login', ['login' => 'sara@example.com', 'password' => 'wrong999'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
        Mail::assertNothingSent();

        // By phone number too
        $this->postJson('/api/v1/auth/login', ['login' => '0'.substr($user->phone, 3), 'password' => 'secret123'])->assertOk()->assertJsonPath('data.purpose', 'login');
        $code = $this->code('sara@example.com', 'login');
        $this->postJson('/api/v1/auth/login/verify', ['email' => 'sara@example.com', 'code' => $code === '000000' ? '111111' : '000000'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login/verify', ['email' => 'sara@example.com', 'code' => $code])->assertOk()->assertJsonPath('data.is_new_user', false);
        $this->postJson('/api/v1/auth/login/verify', ['email' => 'sara@example.com', 'code' => $code])->assertStatus(422); // single use
    }

    public function test_forgot_and_reset_password(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['email' => 'sara@example.com', 'password' => 'secret123', 'email_verified_at' => now()])->save();
        $user->createToken('old');

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'sara@example.com'])->assertOk();
        $code = $this->code('sara@example.com', 'reset');
        $this->postJson('/api/v1/auth/password/reset', ['email' => 'sara@example.com', 'code' => $code, 'password' => 'newpass456', 'password_confirmation' => 'newpass456'])->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['login' => 'sara@example.com', 'password' => 'secret123'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['login' => 'sara@example.com', 'password' => 'newpass456'])->assertOk();
    }

    public function test_review_emails_use_fixed_code_and_send_nothing(): void
    {
        config(['femalerider.otp.test_emails' => ['reviewer@example.com'], 'femalerider.otp.test_code' => '246810']);
        $user = $this->makeUser();
        $user->forceFill(['email' => 'reviewer@example.com', 'password' => 'secret123', 'email_verified_at' => now()])->save();
        $this->postJson('/api/v1/auth/login', ['login' => 'reviewer@example.com', 'password' => 'secret123'])->assertOk();
        Mail::assertNothingSent();
        $this->postJson('/api/v1/auth/login/verify', ['email' => 'reviewer@example.com', 'code' => '246810'])->assertOk();
    }
}
