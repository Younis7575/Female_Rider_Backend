<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Faq;
use App\Models\VehicleCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class PageController extends Controller
{
    public function home(): View
    {
        return view('website.pages.home', [
            'categories' => $this->categories(),
            'cities' => $this->cities(),
            'faqs' => Faq::query()
                ->where('is_published', true)
                ->whereIn('audience', ['all', 'customers'])
                ->orderBy('sort_order')
                ->limit(4)
                ->get(),
        ]);
    }

    public function about(): View
    {
        return view('website.pages.about', [
            'cities' => $this->cities(),
        ]);
    }

    public function safety(): View
    {
        return view('website.pages.safety');
    }

    public function drive(): View
    {
        $categories = $this->categories();

        return view('website.pages.drive', [
            'categories' => $categories,
            'cities' => $this->cities(),
            'commissionMin' => $categories->min('commission_percent'),
            'commissionMax' => $categories->max('commission_percent'),
        ]);
    }

    public function faq(Request $request): View
    {
        $audience = in_array($request->query('audience'), ['customers', 'drivers'], true)
            ? $request->query('audience')
            : 'all';

        $faqs = Faq::query()
            ->where('is_published', true)
            ->when($audience !== 'all', fn ($q) => $q->whereIn('audience', ['all', $audience]))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Faq $f) => $f->category ?: 'general');

        return view('website.pages.faq', [
            'groups' => $faqs,
            'audience' => $audience,
        ]);
    }

    /** @return Collection<int, VehicleCategory> */
    private function categories(): Collection
    {
        return VehicleCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, City> */
    private function cities(): Collection
    {
        return City::query()->where('is_active', true)->orderBy('name')->get();
    }
}
