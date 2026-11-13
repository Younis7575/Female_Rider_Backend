<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = collect(['website.home', 'website.about', 'website.safety', 'website.drive', 'website.faq', 'website.contact'])
            ->map(fn (string $name) => ['loc' => route($name), 'lastmod' => null]);

        CmsPage::query()->where('is_published', true)->get(['slug', 'updated_at'])
            ->each(function (CmsPage $p) use ($urls) {
                $loc = in_array($p->slug, CmsPageController::LEGAL_SLUGS, true)
                    ? route('website.legal', $p->slug)
                    : route('website.page', $p->slug);
                $urls->push(['loc' => $loc, 'lastmod' => $p->updated_at?->toDateString()]);
            });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>'.e($u['loc']).'</loc>'.($u['lastmod'] ? '<lastmod>'.$u['lastmod'].'</lastmod>' : '').'</url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
