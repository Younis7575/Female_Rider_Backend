@php
    $siteName = 'FemaleRider';
    $pageTitle = trim($__env->yieldContent('title'));
    $fullTitle = $pageTitle ? $pageTitle.' · '.$siteName : $siteName.' — Women-only rides in Pakistan';
    $description = trim($__env->yieldContent('description')) ?: 'FemaleRider is a women-only ride-hailing platform in Pakistan. Verified women drivers, fares you set, trip PIN, live trip sharing and SOS. Launching in Karachi, Lahore and Islamabad.';
    $robots = trim($__env->yieldContent('robots')) ?: 'index, follow';
    $canonical = trim($__env->yieldContent('canonical')) ?: url()->current();
    $ogImage = asset('images/logo-square.png');
    $support = config('femalerider.support', []);
    $nav = [
        ['label' => 'How it works', 'href' => route('website.home').'#how-it-works', 'active' => false],
        ['label' => 'Safety', 'href' => route('website.safety'), 'active' => request()->routeIs('website.safety')],
        ['label' => 'Drive with us', 'href' => route('website.drive'), 'active' => request()->routeIs('website.drive')],
        ['label' => 'About', 'href' => route('website.about'), 'active' => request()->routeIs('website.about')],
        ['label' => 'FAQ', 'href' => route('website.faq'), 'active' => request()->routeIs('website.faq')],
        ['label' => 'Contact', 'href' => route('website.contact'), 'active' => request()->routeIs('website.contact')],
    ];
    $minimal = trim($__env->yieldContent('minimal')) === '1';
@endphp
<!DOCTYPE html>
<html lang="en-PK" class="scroll-pt-24">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $fullTitle }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ $robots }}">
    <meta name="theme-color" content="#c8102e">
    @if (! str_contains($robots, 'noindex'))
        <link rel="canonical" href="{{ $canonical }}">
    @endif

    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="en_PK">
    <meta property="og:title" content="{{ $fullTitle }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:alt" content="FemaleRider logo">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $fullTitle }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $ogImage }}">

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

    <script>document.documentElement.classList.add('js')</script>
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col">
    <a href="#main" class="sr-only z-[60] rounded-full bg-crimson-700 px-4 py-2 font-semibold text-white focus:not-sr-only focus:fixed focus:top-3 focus:left-3">Skip to content</a>

    <header data-site-header class="sticky top-0 z-50 border-b border-ink/5 bg-white/85 backdrop-blur-xl transition-shadow">
        <div class="container-site flex h-[72px] items-center justify-between gap-6">
            <a href="{{ route('website.home') }}" class="flex shrink-0 items-center gap-2.5" aria-label="FemaleRider home">
                <img src="{{ asset('images/logo.png') }}" alt="" width="56" height="45" class="h-10 w-auto">
                <span class="font-display text-lg font-extrabold tracking-tight text-ink">Female<span class="text-crimson-600">Rider</span></span>
            </a>

            @unless ($minimal)
                <nav aria-label="Main" class="hidden items-center gap-1 lg:flex">
                    @foreach ($nav as $item)
                        <a href="{{ $item['href'] }}" @if($item['active']) aria-current="page" @endif
                           class="rounded-full px-3.5 py-2 text-sm font-medium transition {{ $item['active'] ? 'bg-crimson-50 text-crimson-700' : 'text-ink-soft hover:bg-ink/5 hover:text-ink' }}">{{ $item['label'] }}</a>
                    @endforeach
                </nav>

                <div class="hidden items-center gap-3 lg:flex">
                    <a href="{{ route('website.home') }}#download" class="btn-primary !px-5 !py-2.5">Get the app</a>
                </div>

                <button type="button" data-nav-toggle aria-expanded="false" aria-controls="mobile-menu"
                        class="inline-flex size-11 items-center justify-center rounded-full text-ink hover:bg-ink/5 lg:hidden">
                    <span class="sr-only">Toggle menu</span>
                    <span data-icon-open>@include('website.partials.icon', ['name' => 'menu', 'class' => 'size-6'])</span>
                    <span data-icon-close class="hidden">@include('website.partials.icon', ['name' => 'x', 'class' => 'size-6'])</span>
                </button>
            @else
                @yield('header-right')
            @endunless
        </div>

        @unless ($minimal)
            <div id="mobile-menu" data-nav-menu class="hidden h-[calc(100dvh-72px)] overflow-y-auto border-t border-ink/5 bg-white lg:hidden">
                <nav aria-label="Mobile" class="container-site flex flex-col gap-1 py-6">
                    @foreach ($nav as $item)
                        <a href="{{ $item['href'] }}" @if($item['active']) aria-current="page" @endif
                           class="rounded-2xl px-4 py-3.5 text-lg font-semibold {{ $item['active'] ? 'bg-crimson-50 text-crimson-700' : 'text-ink hover:bg-ink/5' }}">{{ $item['label'] }}</a>
                    @endforeach
                    <a href="{{ route('website.home') }}#download" class="btn-primary mt-4 !py-4 text-base">Get the app</a>
                    <a href="{{ route('website.drive') }}" class="btn-ghost !py-4 text-base">Become a driver</a>
                </nav>
            </div>
        @endunless
    </header>

    <main id="main" class="flex-1" tabindex="-1">
        @yield('content')
    </main>

    @unless ($minimal)
        <footer class="relative overflow-hidden bg-ink text-white/75">
            <div aria-hidden="true" class="pointer-events-none absolute -top-40 right-0 size-[480px] rounded-full bg-crimson-700/25 blur-3xl"></div>
            <div class="container-site relative py-16">
                <div class="grid gap-12 lg:grid-cols-12">
                    <div class="lg:col-span-4">
                        <a href="{{ route('website.home') }}" class="inline-flex items-center gap-2.5" aria-label="FemaleRider home">
                            <span class="flex size-12 items-center justify-center rounded-2xl bg-white p-1.5"><img src="{{ asset('images/logo.png') }}" alt="" width="40" height="32" class="h-auto w-full"></span>
                            <span class="font-display text-xl font-extrabold text-white">FemaleRider</span>
                        </a>
                        <p class="mt-5 max-w-sm text-sm leading-relaxed">Rides by women, for women. Verified drivers, fares you agree on, and safety tools built into every trip.</p>
                        <div class="mt-6 rounded-2xl border border-white/10 bg-white/5 p-4 text-sm">
                            <p class="font-semibold text-white">In an emergency, call <a href="tel:15" class="text-rose-accent underline underline-offset-2">15</a>.</p>
                            <p class="mt-1 text-white/60">FemaleRider is not an emergency service.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-8 sm:grid-cols-3 lg:col-span-8">
                        <div>
                            <h2 class="font-sans text-sm font-semibold tracking-wide text-white">Company</h2>
                            <ul class="mt-4 space-y-3 text-sm">
                                <li><a class="hover:text-white" href="{{ route('website.about') }}">About us</a></li>
                                <li><a class="hover:text-white" href="{{ route('website.safety') }}">Safety</a></li>
                                <li><a class="hover:text-white" href="{{ route('website.drive') }}">Drive with us</a></li>
                                <li><a class="hover:text-white" href="{{ route('website.contact') }}">Contact</a></li>
                            </ul>
                        </div>
                        <div>
                            <h2 class="font-sans text-sm font-semibold tracking-wide text-white">Legal</h2>
                            <ul class="mt-4 space-y-3 text-sm">
                                <li><a class="hover:text-white" href="{{ route('website.legal', 'terms') }}">Terms of Service</a></li>
                                <li><a class="hover:text-white" href="{{ route('website.legal', 'privacy') }}">Privacy Policy</a></li>
                                <li><a class="hover:text-white" href="{{ route('website.legal', 'eligibility-policy') }}">Eligibility Policy</a></li>
                                <li><a class="hover:text-white" href="{{ route('website.legal', 'driver-agreement') }}">Driver Agreement</a></li>
                            </ul>
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <h2 class="font-sans text-sm font-semibold tracking-wide text-white">Support</h2>
                            <ul class="mt-4 space-y-3 text-sm">
                                <li><a class="hover:text-white" href="{{ route('website.faq') }}">Help &amp; FAQ</a></li>
                                @if (! empty($support['email']))
                                    <li><a class="break-all hover:text-white" href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a></li>
                                @endif
                                @if (! empty($support['phone']))
                                    <li><a class="hover:text-white" href="tel:{{ preg_replace('/[^0-9+]/', '', $support['phone']) }}">{{ $support['phone'] }}</a></li>
                                @endif
                                @if (! empty($support['hours']))
                                    <li class="text-white/55">{{ $support['hours'] }}</li>
                                @endif
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="mt-14 flex flex-col gap-3 border-t border-white/10 pt-8 text-xs text-white/50 sm:flex-row sm:items-center sm:justify-between">
                    <p>&copy; {{ now()->year }} FemaleRider. A product of Gopang IT Solution. All rights reserved.</p>
                    <p>Made for Karachi, Lahore &amp; Islamabad · Fares in PKR</p>
                </div>
            </div>
        </footer>
    @endunless

    @stack('scripts')
</body>
</html>
