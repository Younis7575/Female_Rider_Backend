<?php

namespace Tests\Feature\Website;

use App\Http\Controllers\Website\ContactController;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ayesha Khan',
            'email' => 'ayesha@example.com',
            'phone' => '',
            'subject' => 'general',
            'message' => 'When will FemaleRider launch in Lahore?',
            ContactController::HONEYPOT => '',
        ], $overrides);
    }

    public function test_contact_page_has_csrf_token_and_honeypot(): void
    {
        $this->get('/contact')
            ->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="'.ContactController::HONEYPOT.'"', false);
    }

    public function test_valid_message_is_persisted_and_flashes_success(): void
    {
        $this->post('/contact', $this->validPayload())
            ->assertRedirect(route('website.contact'))
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('contact_messages', 1);
        $msg = ContactMessage::query()->first();
        $this->assertSame('Ayesha Khan', $msg->name);
        $this->assertSame('ayesha@example.com', $msg->email);
        $this->assertSame('General question', $msg->subject);
        $this->assertSame('new', $msg->status);
        $this->assertSame('127.0.0.1', $msg->ip_address);

    }

    public function test_success_flash_is_shown_after_redirect(): void
    {
        $this->followingRedirects()
            ->post('/contact', $this->validPayload())
            ->assertOk()
            ->assertSee('your message has been received');
    }

    public function test_phone_only_contact_is_accepted(): void
    {
        $this->post('/contact', $this->validPayload(['email' => '', 'phone' => '+92 300 1234567']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contact_messages', ['phone' => '+92 300 1234567', 'email' => null]);
    }

    public function test_validation_errors(): void
    {
        $this->from('/contact')
            ->post('/contact', [
                'name' => '',
                'email' => 'not-an-email',
                'subject' => 'made-up',
                'message' => 'short',
            ])
            ->assertRedirect('/contact')
            ->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

        $this->from('/contact')
            ->post('/contact', $this->validPayload(['email' => '', 'phone' => '']))
            ->assertSessionHasErrors(['email', 'phone']);

        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_honeypot_submission_is_silently_discarded(): void
    {
        $this->post('/contact', $this->validPayload([ContactController::HONEYPOT => 'https://spam.example']))
            ->assertRedirect(route('website.contact'));

        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_contact_form_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/contact', $this->validPayload())->assertRedirect();
        }

        $this->post('/contact', $this->validPayload())->assertStatus(429);
        $this->assertDatabaseCount('contact_messages', 5);
    }

    public function test_html_is_stripped_from_message(): void
    {
        $this->post('/contact', $this->validPayload(['message' => '<b>Hello</b> <script>x()</script>there friends']))
            ->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('<', ContactMessage::query()->first()->message);
    }
}
