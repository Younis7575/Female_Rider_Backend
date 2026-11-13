@extends('website.layouts.app')

@section('title', 'FAQ')
@section('description', 'Answers to common questions about FemaleRider — who can ride, how fares work, trip PIN, SOS, payments and driving with us.')

@section('content')
    @include('website.partials.page-hero', [
        'eyebrow' => 'Help centre',
        'icon' => 'chat',
        'title' => 'Frequently asked questions',
        'lead' => 'Everything you need to know about riding and driving with FemaleRider.',
    ])

    <section class="section">
        <div class="container-site grid gap-12 lg:grid-cols-12">
            <aside class="lg:col-span-4">
                <div class="lg:sticky lg:top-28">
                    <h2 class="font-sans text-sm font-semibold tracking-wide text-ink-soft uppercase" id="audience-label">Show questions for</h2>
                    <nav aria-labelledby="audience-label" class="mt-4 flex flex-wrap gap-2 lg:flex-col">
                        @foreach (['all' => 'Everyone', 'customers' => 'Riders', 'drivers' => 'Drivers'] as $key => $label)
                            <a href="{{ $key === 'all' ? route('website.faq') : route('website.faq', ['audience' => $key]) }}"
                               @if ($audience === $key) aria-current="page" @endif
                               class="rounded-full px-5 py-2.5 text-sm font-semibold transition lg:rounded-2xl {{ $audience === $key ? 'bg-crimson-600 text-white shadow-lift' : 'bg-ink/5 text-ink hover:bg-crimson-50 hover:text-crimson-700' }}">{{ $label }}</a>
                        @endforeach
                    </nav>

                    @if ($groups->count() > 1)
                        <h2 class="mt-10 hidden font-sans text-sm font-semibold tracking-wide text-ink-soft uppercase lg:block">Topics</h2>
                        <ul class="mt-4 hidden space-y-2 text-sm lg:block">
                            @foreach ($groups->keys() as $category)
                                <li><a href="#topic-{{ Str::slug($category) }}" class="text-ink-soft hover:text-crimson-700">{{ Str::headline($category) }}</a></li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="mt-10 rounded-3xl bg-blush p-6">
                        <p class="font-semibold">Still have questions?</p>
                        <p class="mt-1 text-sm text-ink-soft">Our support team is happy to help.</p>
                        <a href="{{ route('website.contact') }}" class="btn-primary mt-4 !px-5 !py-2.5">Contact us</a>
                    </div>
                </div>
            </aside>

            <div class="lg:col-span-8">
                @forelse ($groups as $category => $faqs)
                    <section id="topic-{{ Str::slug($category) }}" class="scroll-mt-28 {{ $loop->first ? '' : 'mt-14' }}" aria-labelledby="h-{{ Str::slug($category) }}">
                        <h2 id="h-{{ Str::slug($category) }}" class="text-2xl font-extrabold">{{ Str::headline($category) }}</h2>
                        <div class="mt-6 space-y-3">
                            @foreach ($faqs as $faq)
                                @include('website.partials.faq-item', ['faq' => $faq])
                            @endforeach
                        </div>
                    </section>
                @empty
                    <div class="rounded-3xl border border-dashed border-ink/15 p-10 text-center text-ink-soft">
                        No questions here yet. <a href="{{ route('website.contact') }}" class="font-semibold text-crimson-700 underline">Ask us directly</a>.
                    </div>
                @endforelse
            </div>
        </div>
    </section>
@endsection
