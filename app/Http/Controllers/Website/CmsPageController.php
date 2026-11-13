<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\CmsPage;
use Illuminate\View\View;

class CmsPageController extends Controller
{
    /** Slugs served at the site root (e.g. /terms). Other CMS pages live under /pages/{slug}. */
    public const LEGAL_SLUGS = ['terms', 'privacy', 'eligibility-policy', 'driver-agreement', 'delete-account'];

    public function show(string $slug): View
    {
        $page = CmsPage::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        abort_if($page === null, 404);

        return view('website.legal.show', [
            'page' => $page,
            'body' => self::sanitize((string) $page->body),
        ]);
    }

    /**
     * Body HTML is authored by admins, but strip active content defensively:
     * script/style/iframe/object/embed blocks, inline event handlers and javascript: URLs.
     */
    public static function sanitize(string $html): string
    {
        $html = preg_replace('#<(script|style|iframe|object|embed|noscript|template)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('#<(script|style|iframe|object|embed|noscript|template|link|meta|base)\b[^>]*/?>#i', '', $html) ?? '';
        $html = preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? '';
        $html = preg_replace('#(href|src|xlink:href|action|formaction)\s*=\s*(["\']?)\s*(javascript|vbscript|data):#i', '$1=$2#blocked:', $html) ?? '';

        return $html;
    }
}
