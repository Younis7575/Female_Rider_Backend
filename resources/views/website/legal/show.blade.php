@extends('website.layouts.app')

@section('title', $page->title)
@section('description', Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 155))

@section('content')
    <section class="border-b border-ink/5 bg-brand-soft">
        <div class="container-site py-16 sm:py-20">
            <nav aria-label="Breadcrumb" class="text-sm text-ink-soft">
                <ol class="flex items-center gap-2">
                    <li><a href="{{ route('website.home') }}" class="hover:text-crimson-700">Home</a></li>
                    <li aria-hidden="true">/</li>
                    <li aria-current="page" class="font-medium text-ink">{{ $page->title }}</li>
                </ol>
            </nav>
            <h1 class="mt-5 max-w-3xl text-4xl font-extrabold sm:text-5xl">{{ $page->title }}</h1>
            <p class="mt-4 text-sm text-ink-soft">
                Version {{ $page->version }}
                @if ($page->updated_at) · Last updated <time datetime="{{ $page->updated_at->toDateString() }}">{{ $page->updated_at->format('j F Y') }}</time>@endif
            </p>
        </div>
    </section>

    <section class="py-14 sm:py-20">
        <div class="container-site grid gap-12 lg:grid-cols-12">
            <article class="prose-site lg:col-span-8">
                {!! $body !!}
            </article>
            <aside class="lg:col-span-4">
                <div class="lg:sticky lg:top-28 space-y-5">
                    <nav aria-label="Legal documents" class="card !p-6">
                        <h2 class="font-sans text-sm font-semibold tracking-wide text-ink-soft uppercase">Legal</h2>
                        <ul class="mt-4 space-y-1">
                            @foreach (['terms' => 'Terms of Service', 'privacy' => 'Privacy Policy', 'eligibility-policy' => 'Eligibility Policy', 'driver-agreement' => 'Driver Agreement'] as $slug => $label)
                                <li>
                                    <a href="{{ route('website.legal', $slug) }}" @if ($page->slug === $slug) aria-current="page" @endif
                                       class="block rounded-xl px-3 py-2 text-sm font-medium {{ $page->slug === $slug ? 'bg-crimson-50 text-crimson-700' : 'text-ink hover:bg-ink/5' }}">{{ $label }}</a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                    <div class="rounded-3xl bg-blush p-6 text-sm text-ink-soft">
                        Questions about this document? <a href="{{ route('website.contact') }}" class="font-semibold text-crimson-700 underline underline-offset-2">Contact us</a>.
                    </div>
                </div>
            </aside>
        </div>
    </section>
@endsection
