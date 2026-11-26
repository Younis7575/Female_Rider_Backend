<?php

namespace Tests\Feature\Website;

use App\Http\Controllers\Website\AppDownloadController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_android_download_serves_the_apk(): void
    {
        Storage::fake('local');
        $this->get('/download/android')->assertRedirect(route('website.home').'#download');

        Storage::disk('local')->put(AppDownloadController::APK_PATH, 'apk-bytes');
        $res = $this->get('/download/android', ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14)']);
        $res->assertOk()->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        $this->assertStringContainsString('FemaleRider.apk', $res->headers->get('Content-Disposition'));

        $this->get('/download/android', ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)'])->assertRedirect(route('website.home').'#download');
        $this->get('/app')->assertRedirect('/download/android');
    }

    public function test_home_get_the_app_links_to_download(): void
    {
        $this->get('/')->assertOk()->assertSee(route('website.download.android'), false)->assertSee('Now on Android');
    }
}
