<?php

namespace Tests\Feature\Api;

use App\Jobs\SendPushNotification;
use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Services\Notifications\FcmService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class NotificationsAndSupportTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
    }

    public function test_device_registration_supports_multiple_devices_and_removal(): void
    {
        $u = $this->makeUser();
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/devices', ['token' => 'tok-a', 'platform' => 'android'])->assertOk();
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/devices', ['token' => 'tok-b', 'platform' => 'ios'])->assertOk();
        $this->actingAs($u, 'sanctum')->postJson('/api/v1/devices', ['token' => 'tok-a', 'platform' => 'android'])->assertOk(); // re-register is upsert
        $this->assertSame(2, DeviceToken::query()->where('user_id', $u->id)->count());

        // Same token logging into another account moves to that account.
        $other = $this->makeUser();
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/devices', ['token' => 'tok-b', 'platform' => 'ios'])->assertOk();
        $this->assertSame(1, DeviceToken::query()->where('user_id', $u->id)->count());

        $this->actingAs($u, 'sanctum')->deleteJson('/api/v1/devices', ['token' => 'tok-a'])->assertOk();
        $this->assertNotNull(DeviceToken::query()->where('token_hash', hash('sha256', 'tok-a'))->value('revoked_at'));
    }

    public function test_ride_events_create_inbox_notifications_and_push_is_skipped_without_fcm(): void
    {
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        $this->assignedTrip($customer, $driver);

        $this->assertTrue(AppNotification::query()->where('user_id', $customer->id)->where('type', 'trip.assigned')->exists());
        $this->assertTrue(AppNotification::query()->where('user_id', $driver->id)->where('type', 'ride.new_request')->exists());
        $this->assertEquals('skipped', AppNotification::query()->where('user_id', $customer->id)->latest('id')->value('push_status'));

        $res = $this->actingAs($customer, 'sanctum')->getJson('/api/v1/notifications')->assertOk();
        $this->assertGreaterThan(0, $res->json('meta.unread'));
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->actingAs($customer, 'sanctum')->getJson('/api/v1/notifications')->json('meta.unread'));
    }

    public function test_push_job_revokes_invalid_tokens_and_records_partial_delivery(): void
    {
        $u = $this->makeUser();
        DeviceToken::query()->create(['user_id' => $u->id, 'token' => 'good', 'token_hash' => hash('sha256', 'good'), 'platform' => 'android']);
        DeviceToken::query()->create(['user_id' => $u->id, 'token' => 'dead', 'token_hash' => hash('sha256', 'dead'), 'platform' => 'android']);

        $fake = new class extends FcmService
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function sendToToken(string $token, string $title, string $body, array $data = [], string $channel = 'femalerider_default', ?string $tag = null): array
            {
                return $token === 'good'
                    ? ['ok' => true, 'invalid_token' => false, 'error' => null, 'message_id' => 'm1']
                    : ['ok' => false, 'invalid_token' => true, 'error' => 'UNREGISTERED', 'message_id' => null];
            }
        };
        $this->app->instance(FcmService::class, $fake);

        $n = AppNotification::query()->create(['user_id' => $u->id, 'type' => 'trip.started', 'title' => 'T', 'body' => 'B', 'push_status' => 'pending']);
        (new SendPushNotification($n->id))->handle($fake);

        $this->assertEquals('partial', $n->fresh()->push_status);
        $this->assertNotNull(DeviceToken::query()->where('token', 'dead')->value('revoked_at'));
        $this->assertNull(DeviceToken::query()->where('token', 'good')->value('revoked_at'));
    }

    public function test_support_ticket_flow_and_ownership(): void
    {
        $u = $this->makeUser();
        $ref = $this->actingAs($u, 'sanctum')->postJson('/api/v1/support/tickets', ['category' => 'lost_item', 'subject' => 'Left my bag', 'message' => 'Black handbag on back seat'])
            ->assertCreated()->assertJsonPath('data.priority', 'high')->json('data.reference');
        $this->actingAs($u, 'sanctum')->postJson("/api/v1/support/tickets/{$ref}/messages", ['message' => 'Any update?'])->assertOk();
        $this->actingAs($this->makeUser(), 'sanctum')->getJson("/api/v1/support/tickets/{$ref}")->assertStatus(404);
    }

    public function test_notification_service_respects_marketing_preferences(): void
    {
        $u = $this->makeUser();
        $u->forceFill(['notification_preferences' => ['promotions' => false]])->save();
        $n = app(NotificationService::class)->send($u, 'promotions.offer', 'Promo', 'Discount');
        $this->assertEquals('skipped', $n->push_status);
        $this->assertDatabaseHas('notifications', ['id' => $n->id]); // still in inbox
    }
}
