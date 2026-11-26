<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Direct Android download for marketing ("Get the app"). The signed APK is uploaded to
 * storage/app/downloads/FemaleRider.apk on the server (kept out of git, survives deploys).
 */
class AppDownloadController extends Controller
{
    public const APK_PATH = 'downloads/FemaleRider.apk';

    public function android(Request $request): Response
    {
        // iPhone users can't install an APK: send them to the download section instead.
        if (preg_match('/iPhone|iPad|iPod/i', (string) $request->userAgent())) {
            return redirect()->to(route('website.home').'#download')->with('ios_notice', true);
        }
        $disk = Storage::disk('local');
        if (! $disk->exists(self::APK_PATH)) {
            return redirect()->to(route('website.home').'#download');
        }
        Cache::increment('app_downloads.android');

        return $disk->download(self::APK_PATH, 'FemaleRider.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public static function available(): bool
    {
        return Storage::disk('local')->exists(self::APK_PATH);
    }
}
