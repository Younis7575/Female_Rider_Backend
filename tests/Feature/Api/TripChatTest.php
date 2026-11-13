<?php

namespace Tests\Feature\Api;

use App\Events\RealtimeEvent;
use App\Filament\Resources\Trips\Pages\ViewTrip;
use App\Filament\Resources\Trips\RelationManagers\MessagesRelationManager;
use App\Jobs\SendChatPush;
use App\Models\Trip;
use App\Models\TripMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\BuildsFemaleRiderWorld;
use Tests\TestCase;

class TripChatTest extends TestCase
{
    use BuildsFemaleRiderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorld();
    }

    public function test_customer_and_driver_chat_privately_after_offer_is_accepted(): void
    {
        Queue::fake();
        Event::fake([RealtimeEvent::class]);
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        [, $tripId] = $this->assignedTrip($customer, $driver);

        $this->actingAs($customer, 'sanctum')->getJson("/api/v1/trips/{$tripId}")
            ->assertOk()->assertJsonPath('data.chat.enabled', true)->assertJsonPath('data.chat.unread', 0);

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/messages", ['body' => '  I am at the main gate 🙂 ', 'client_id' => 'c-1'])
            ->assertCreated()->assertJsonPath('data.body', 'I am at the main gate 🙂')->assertJsonPath('data.sender_role', 'customer');
        // Retried send with the same client id is not duplicated
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/messages", ['body' => 'I am at the main gate 🙂', 'client_id' => 'c-1'])->assertCreated();
        $this->assertSame(1, TripMessage::query()->count());

        Event::assertDispatched(RealtimeEvent::class, fn ($e) => $e->eventName === 'trip.message' && $e->channelNames === ["trip.{$tripId}"]);
        Queue::assertPushed(SendChatPush::class, fn ($j) => $j->recipientId === $driver->id);

        // Driver sees it as unread, replies, then reads
        $this->actingAs($driver, 'sanctum')->getJson("/api/v1/trips/{$tripId}")->assertJsonPath('data.chat.unread', 1);
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/messages", ['body' => 'Coming in 2 minutes'])->assertCreated();
        $list = $this->actingAs($driver, 'sanctum')->getJson("/api/v1/trips/{$tripId}/messages")->assertOk();
        $this->assertSame(['customer', 'driver'], collect($list->json('data.messages'))->pluck('sender_role')->all());
        $this->assertTrue($list->json('data.can_send'));
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/messages/read")->assertOk()->assertJsonPath('data.marked', 1);
        $this->actingAs($driver, 'sanctum')->getJson("/api/v1/trips/{$tripId}")->assertJsonPath('data.chat.unread', 0);

        // Incremental fetch
        $firstSeq = $list->json('data.messages.0.seq');
        $this->assertCount(1, $this->actingAs($customer, 'sanctum')->getJson("/api/v1/trips/{$tripId}/messages?after={$firstSeq}")->json('data.messages'));

        // Encrypted at rest
        $raw = DB::table('trip_messages')->where('sender_role', 'customer')->value('body');
        $this->assertStringNotContainsString('main gate', $raw);
    }

    public function test_outsiders_cannot_read_or_write_the_chat(): void
    {
        Queue::fake();
        [, $tripId] = $this->assignedTrip($this->makeUser(), $this->makeDriver());
        $stranger = $this->makeUser();
        $otherDriver = $this->makeDriver();

        foreach ([$stranger, $otherDriver] as $user) {
            $this->actingAs($user, 'sanctum')->getJson("/api/v1/trips/{$tripId}/messages")->assertStatus(404);
            $this->actingAs($user, 'sanctum')->postJson("/api/v1/trips/{$tripId}/messages", ['body' => 'hi'])->assertStatus(404);
        }
        $this->assertSame(0, TripMessage::query()->count());
    }

    public function test_chat_becomes_read_only_when_the_trip_ends_and_rejects_bad_input(): void
    {
        Queue::fake();
        $customer = $this->makeUser();
        $driver = $this->makeDriver();
        [, $tripId] = $this->assignedTrip($customer, $driver);

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/messages", ['body' => "   \n "])->assertStatus(422);
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/messages", ['body' => str_repeat('a', 501)])->assertStatus(422)->assertJsonPath('error.code', 'message_too_long');
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/messages", ['body' => 'See you'])->assertCreated();

        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/cancel", ['reason' => 'Plans changed'])->assertOk();
        $this->assertFalse(Trip::query()->where('uuid', $tripId)->first()->status->isActive());

        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/trips/{$tripId}/messages", ['body' => 'Hello?'])->assertStatus(409)->assertJsonPath('error.code', 'chat_closed');
        $this->actingAs($driver, 'sanctum')->getJson("/api/v1/trips/{$tripId}/messages")->assertOk()->assertJsonPath('data.can_send', false)->assertJsonCount(1, 'data.messages');
        $this->actingAs($driver, 'sanctum')->getJson("/api/v1/trips/{$tripId}")->assertJsonPath('data.chat.enabled', false);
    }

    public function test_only_safety_staff_can_read_a_trip_chat_in_the_admin_panel(): void
    {
        Queue::fake();
        $customer = $this->makeUser();
        [, $tripId] = $this->assignedTrip($customer, $this->makeDriver());
        $trip = Trip::query()->where('uuid', $tripId)->first();
        $this->actingAs($customer, 'sanctum')->postJson("/api/v1/trips/{$tripId}/messages", ['body' => 'Blue gate please'])->assertCreated();

        $page = ViewTrip::class;
        $rm = MessagesRelationManager::class;

        $this->actingAs($this->makeAdmin('customer_support'), 'admin');
        $this->assertFalse($rm::canViewForRecord($trip, $page));

        $this->actingAs($this->makeAdmin('safety_officer'), 'admin');
        $this->assertTrue($rm::canViewForRecord($trip, $page));
        Livewire::test($rm, ['ownerRecord' => $trip, 'pageClass' => $page])
            ->assertCanSeeTableRecords($trip->messages()->get())
            ->assertSee('Blue gate please');
    }
}
