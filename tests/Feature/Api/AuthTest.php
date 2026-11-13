<?php

namespace Tests\Feature\Api;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
    }

    public function test_otp_request_and_verify_creates_new_user_with_pending_customer_role(): void
    {
        $code = $this->postJson('/api/v1/auth/otp/request', ['country_code' => '+92', 'phone' => '0300 1112233'])
            ->assertOk()->assertJsonPath('data.phone', '+923001112233')->json('data.debug_code');

        $this->assertNotNull($code);
        $this->assertNotEquals($code, OtpCode::query()->first()->code_hash, 'OTP must be stored hashed');

        $res = $this->postJson('/api/v1/auth/otp/verify', ['country_code' => '+92', 'phone' => '3001112233', 'code' => $code])
            ->assertOk()->assertJsonPath('data.is_new_user', true)->assertJsonPath('data.user.eligibility.status', 'draft');

        $this->assertNotEmpty($res->json('data.token'));
        $user = User::query()->where('phone', '+923001112233')->first();
        $this->assertEquals('pending', $user->roles()->where('role', 'customer')->value('status'));
        $this->assertFalse($res->json('data.user.capabilities.customer_mode'));
        $this->assertSame(2, $user->consents()->count());
    }

    public function test_wrong_code_is_rejected_and_attempts_are_limited(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['country_code' => '+92', 'phone' => '3001112233'])->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/otp/verify', ['country_code' => '+92', 'phone' => '3001112233', 'code' => '000000'])
                ->assertStatus(422)->assertJsonPath('error.code', 'otp_invalid');
        }
        $this->postJson('/api/v1/auth/otp/verify', ['country_code' => '+92', 'phone' => '3001112233', 'code' => '000000'])
            ->assertStatus(429)->assertJsonPath('error.code', 'otp_locked');
    }

    public function test_resend_cooldown_is_enforced(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['country_code' => '+92', 'phone' => '3001112244'])->assertOk();
        $this->postJson('/api/v1/auth/otp/request', ['country_code' => '+92', 'phone' => '3001112244'])
            ->assertStatus(429)->assertJsonPath('error.code', 'otp_cooldown');
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->postJson('/api/v1/auth/otp/request', ['country_code' => '+92', 'phone' => '3001112255'])->json('data.debug_code');
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/auth/otp/verify', ['country_code' => '+92', 'phone' => '3001112255', 'code' => $code])
            ->assertStatus(422)->assertJsonPath('error.code', 'otp_expired');
    }

    public function test_validation_errors_use_envelope(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['country_code' => 'abc'])
            ->assertStatus(422)->assertJsonPath('success', false)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_protected_routes_require_token_and_logout_revokes(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

        $user = $this->makeUser();
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.phone', $user->phone);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_suspended_account_is_blocked(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['account_status' => 'suspended', 'suspension_reason' => 'Policy'])->save();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('error.code', 'account_suspended');
    }

    public function test_account_deletion_request_revokes_tokens(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('t')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/account/delete', ['reason' => 'Moving'])->assertOk();
        $this->assertEquals('pending_deletion', $user->fresh()->account_status);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_qa_test_numbers_use_the_fixed_code_without_sms_even_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['femalerider.otp.test_numbers' => ['+923009990001'], 'femalerider.otp.test_code' => '246810', 'femalerider.otp.expose_in_response' => true]);
        $sms = \Mockery::mock(SmsSender::class);
        $sms->shouldReceive('send')->once(); // only for the real number below
        $this->app->instance(SmsSender::class, $sms);

        $this->postJson('/api/v1/auth/otp/request', ['country_code' => '+92', 'phone' => '3009990001'])->assertOk()->assertJsonPath('data.debug_code', null);
        $this->postJson('/api/v1/auth/otp/verify', ['country_code' => '+92', 'phone' => '3009990001', 'code' => '246810'])->assertOk()->assertJsonPath('data.is_new_user', true);

        // A number that is not on the list gets a random code by SMS and is never exposed in production.
        $this->postJson('/api/v1/auth/otp/request', ['country_code' => '+92', 'phone' => '3009990002'])->assertOk()->assertJsonPath('data.debug_code', null);
        $this->postJson('/api/v1/auth/otp/verify', ['country_code' => '+92', 'phone' => '3009990002', 'code' => '246810'])->assertStatus(422);
    }
}
