<?php

namespace Tests\Feature\Website;

use App\Models\City;
use App\Models\CmsPage;
use App\Models\Faq;
use App\Models\VehicleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        VehicleCategory::query()->create([
            'name' => 'FemaleRider Bike', 'slug' => 'bike', 'icon' => 'bike', 'description' => 'Quick solo rides',
            'capacity' => 1, 'base_fare' => 60, 'per_km' => 18, 'per_minute' => 2.5, 'minimum_fare' => 120,
            'commission_percent' => 12, 'is_active' => true, 'sort_order' => 0,
        ]);
        VehicleCategory::query()->create([
            'name' => 'FemaleRider Comfort', 'slug' => 'comfort', 'icon' => 'car_premium', 'description' => 'Spacious sedans',
            'capacity' => 4, 'base_fare' => 200, 'per_km' => 55, 'per_minute' => 5, 'minimum_fare' => 380,
            'commission_percent' => 18, 'is_active' => true, 'sort_order' => 1,
        ]);
        VehicleCategory::query()->create([
            'name' => 'Hidden Category', 'slug' => 'hidden', 'icon' => 'car', 'capacity' => 4, 'base_fare' => 1,
            'per_km' => 1, 'per_minute' => 1, 'minimum_fare' => 999, 'is_active' => false,
        ]);

        City::query()->create(['name' => 'Karachi', 'center_lat' => 24.86, 'center_lng' => 67.0, 'is_active' => true]);
        City::query()->create(['name' => 'Lahore', 'center_lat' => 31.52, 'center_lng' => 74.35, 'is_active' => true]);
        City::query()->create(['name' => 'Quetta', 'center_lat' => 30.18, 'center_lng' => 66.97, 'is_active' => false]);

        Faq::query()->create(['category' => 'general', 'audience' => 'all', 'question' => 'Who can use FemaleRider?', 'answer' => 'Women only.', 'sort_order' => 0, 'is_published' => true]);
        Faq::query()->create(['category' => 'drivers', 'audience' => 'drivers', 'question' => 'What documents do I need to drive?', 'answer' => 'CNIC and licence.', 'sort_order' => 1, 'is_published' => true]);
        Faq::query()->create(['category' => 'payments', 'audience' => 'customers', 'question' => 'How do I pay as a rider?', 'answer' => 'Cash.', 'sort_order' => 2, 'is_published' => true]);
        Faq::query()->create(['category' => 'general', 'audience' => 'all', 'question' => 'Unpublished draft question?', 'answer' => 'Draft.', 'sort_order' => 3, 'is_published' => false]);

        foreach (['terms' => 'Terms of Service', 'privacy' => 'Privacy Policy', 'eligibility-policy' => 'Women-only Eligibility Policy', 'driver-agreement' => 'Driver Agreement'] as $slug => $title) {
            CmsPage::query()->create(['slug' => $slug, 'title' => $title, 'body' => "<p>{$title} body.</p>", 'version' => '1.0', 'is_published' => true]);
        }
    }

    public static function pages(): array
    {
        return [
            'home' => ['/'],
            'about' => ['/about'],
            'safety' => ['/safety'],
            'drive' => ['/drive'],
            'faq' => ['/faq'],
            'contact' => ['/contact'],
            'terms' => ['/terms'],
            'privacy' => ['/privacy'],
            'eligibility' => ['/eligibility-policy'],
            'driver agreement' => ['/driver-agreement'],
            'sitemap' => ['/sitemap.xml'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_returns_ok(string $uri): void
    {
        $this->get($uri)->assertOk();
    }

    public function test_home_shows_active_categories_cities_and_safety_copy(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<title>FemaleRider — Women-only rides in Pakistan</title>', false)
            ->assertSee('og:title', false)
            ->assertSee('images/favicon-32.png', false)
            ->assertSee('FemaleRider Bike')
            ->assertSee('PKR 120')
            ->assertSee('PKR 380')
            ->assertDontSee('Hidden Category')
            ->assertSee('Karachi')
            ->assertSee('Lahore')
            ->assertDontSee('Quetta')
            ->assertSee('call 15', false)
            ->assertSee('Coming soon')
            ->assertSee('Who can use FemaleRider?')
            ->assertDontSee('Unpublished draft question?')
            ->assertDontSee('apps.apple.com')
            ->assertDontSee('play.google.com')
            ->assertSee(route('website.legal', 'terms'), false);
    }

    public function test_drive_page_discloses_commission_range_and_requirements(): void
    {
        $this->get('/drive')
            ->assertOk()
            ->assertSee('12–18%')
            ->assertSee('CNIC')
            ->assertSee('Driving licence')
            ->assertSee('Vehicle registration')
            ->assertSee('Photos');
    }

    public function test_faq_groups_published_questions_and_filters_by_audience(): void
    {
        $this->get('/faq')
            ->assertOk()
            ->assertSee('Who can use FemaleRider?')
            ->assertSee('What documents do I need to drive?')
            ->assertSee('How do I pay as a rider?')
            ->assertDontSee('Unpublished draft question?');

        $this->get('/faq?audience=drivers')
            ->assertOk()
            ->assertSee('Who can use FemaleRider?')
            ->assertSee('What documents do I need to drive?')
            ->assertDontSee('How do I pay as a rider?');

        $this->get('/faq?audience=customers')
            ->assertOk()
            ->assertSee('How do I pay as a rider?')
            ->assertDontSee('What documents do I need to drive?');
    }

    public function test_cms_page_renders_title_and_body(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('<title>Privacy Policy · FemaleRider</title>', false)
            ->assertSee('<p>Privacy Policy body.</p>', false);
    }

    public function test_cms_unknown_slug_returns_404(): void
    {
        $this->get('/pages/does-not-exist')->assertNotFound();
        $this->get('/does-not-exist')->assertNotFound();
    }

    public function test_cms_missing_or_unpublished_legal_page_returns_404(): void
    {
        CmsPage::query()->where('slug', 'terms')->update(['is_published' => false]);
        CmsPage::query()->where('slug', 'driver-agreement')->delete();

        $this->get('/terms')->assertNotFound();
        $this->get('/driver-agreement')->assertNotFound();
    }

    public function test_cms_body_has_scripts_and_event_handlers_stripped(): void
    {
        CmsPage::query()->where('slug', 'terms')->update([
            'body' => '<h3>Heading</h3><p onclick="alert(1)">Safe text</p><script>alert("x")</script><a href="javascript:alert(2)">link</a><iframe src="https://evil.example"></iframe>',
        ]);

        $response = $this->get('/terms')->assertOk();
        $response->assertSee('<h3>Heading</h3>', false)
            ->assertSee('Safe text')
            ->assertDontSee('alert("x")', false)
            ->assertDontSee('onclick', false)
            ->assertDontSee('javascript:alert', false)
            ->assertDontSee('evil.example', false);
    }

    public function test_sitemap_lists_public_pages(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee(route('website.drive'), false)
            ->assertSee(route('website.legal', 'privacy'), false);
    }
}
