@extends('website.layouts.app')

@section('description', 'FemaleRider is a women-only ride-hailing app in Pakistan. Verified women drivers, set your own fare, trip PIN, live trip sharing and SOS — launching in Karachi, Lahore and Islamabad.')

@section('content')
    {{-- HERO --}}
    <section class="relative isolate overflow-hidden bg-brand-soft">
        <div aria-hidden="true" class="pointer-events-none absolute top-24 -right-40 -z-10 size-[640px] rounded-full bg-crimson-100/60 blur-3xl"></div>
        <div class="container-site grid items-center gap-14 pt-14 pb-20 sm:pt-20 lg:grid-cols-12 lg:gap-8 lg:pt-24 lg:pb-28">
            <div class="lg:col-span-6 xl:col-span-6">
                <span class="eyebrow">
                    <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-crimson-500 opacity-60"></span><span class="relative inline-flex size-2 rounded-full bg-crimson-600"></span></span>
                    Women-only rides · Pakistan
                </span>
                <h1 class="mt-6 text-[2.75rem] leading-[1.03] font-extrabold sm:text-6xl lg:text-[4.25rem]">
                    Rides by women,<br><span class="text-gradient">for women.</span>
                </h1>
                <p class="mt-6 max-w-xl text-lg leading-relaxed text-ink-soft sm:text-xl">
                    FemaleRider connects women riders with verified women drivers. Name a fair price, pick the offer you like, and ride with a trip PIN and live sharing built in.
                </p>
                <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                    <a href="#download" class="btn-primary !px-7 !py-3.5 text-base">Get the app</a>
                    <a href="{{ route('website.drive') }}" class="btn-ghost !px-7 !py-3.5 text-base">Become a driver @include('website.partials.icon', ['name' => 'arrow-right', 'class' => 'size-4'])</a>
                </div>
                <dl class="mt-12 grid max-w-xl grid-cols-1 gap-5 sm:grid-cols-3">
                    @foreach ([['users', 'Women only', 'Riders and drivers'], ['tag', 'Fares you set', 'Offer, counter, choose'], ['key', 'PIN pickup', 'No PIN, no trip']] as [$ico, $t, $d])
                        <div class="flex items-center gap-3 sm:flex-col sm:items-start sm:gap-2">
                            <dt class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white text-crimson-600 shadow-soft">@include('website.partials.icon', ['name' => $ico])</dt>
                            <dd><span class="block text-sm font-bold text-ink">{{ $t }}</span><span class="block text-sm text-ink-soft">{{ $d }}</span></dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="relative lg:col-span-6 xl:col-span-5 xl:col-start-8" aria-hidden="true">
                <div class="absolute inset-x-6 top-8 bottom-0 -z-10 rounded-[3rem] bg-brand-gradient sm:inset-x-14"></div>
                <div class="mx-auto w-full max-w-[320px] pt-2">
                    @include('website.partials.phone-mock')
                </div>
                <div class="absolute top-24 -left-2 hidden rounded-2xl bg-white p-4 shadow-lift sm:block lg:-left-6">
                    <p class="text-xs font-medium text-ink/50">Trip PIN</p>
                    <p class="mt-1 flex gap-1.5 font-display text-xl font-extrabold tracking-widest text-crimson-700">
                        @foreach ([4, 8, 2, 7] as $d)<span class="flex size-8 items-center justify-center rounded-lg bg-crimson-50">{{ $d }}</span>@endforeach
                    </p>
                </div>
                <div class="absolute right-0 bottom-16 hidden items-center gap-3 rounded-2xl bg-white p-3.5 pr-5 shadow-lift sm:flex lg:-right-4">
                    <span class="flex size-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">@include('website.partials.icon', ['name' => 'share'])</span>
                    <span><span class="block text-sm font-bold text-ink">Trip shared</span><span class="block text-xs text-ink/55">Your sister is following live</span></span>
                </div>
            </div>
        </div>
    </section>

    {{-- HOW IT WORKS --}}
    <section id="how-it-works" class="section scroll-mt-20">
        <div class="container-site">
            <div class="reveal mx-auto max-w-2xl text-center">
                <span class="eyebrow">How it works</span>
                <h2 class="section-title">Four steps. You stay in control.</h2>
                <p class="section-lead mx-auto">No surprise pricing and no stranger at the door. You decide the fare, you choose the driver, and the ride only starts when you share your PIN.</p>
            </div>
            <ol class="mt-16 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['tag', 'Set your fare', 'Enter your pickup and destination. We suggest a fair price — offer that, or propose your own within a sensible range.'],
                    ['chat', 'Drivers make offers', 'Nearby verified women drivers accept your fare or send a counter-offer with their ETA and rating.'],
                    ['check-circle', 'Choose your driver', 'Compare price, rating, vehicle and arrival time. Pick the offer that suits you best.'],
                    ['key', 'Ride with your PIN', 'Confirm the driver and plate match the app, then share your 4-digit PIN. The trip can’t start without it.'],
                ] as $i => [$ico, $t, $d])
                    <li class="reveal card group relative overflow-hidden transition hover:-translate-y-1 hover:shadow-lift" style="transition-delay: {{ $i * 70 }}ms">
                        <span aria-hidden="true" class="absolute -top-4 -right-1 font-display text-[7rem] leading-none font-extrabold text-crimson-50 transition group-hover:text-crimson-100">{{ $i + 1 }}</span>
                        <span class="relative flex size-12 items-center justify-center rounded-2xl bg-crimson-600 text-white shadow-lift">@include('website.partials.icon', ['name' => $ico, 'class' => 'size-6'])</span>
                        <h3 class="relative mt-6 text-xl font-bold"><span class="sr-only">Step {{ $i + 1 }}: </span>{{ $t }}</h3>
                        <p class="relative mt-2 text-[15px] leading-relaxed text-ink-soft">{{ $d }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- SAFETY --}}
    <section class="section relative isolate overflow-hidden bg-ink text-white">
        <div aria-hidden="true" class="pointer-events-none absolute -top-40 -left-40 -z-10 size-[520px] rounded-full bg-crimson-700/30 blur-3xl"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -right-40 -bottom-40 -z-10 size-[520px] rounded-full bg-crimson-900/40 blur-3xl"></div>
        <div class="container-site">
            <div class="reveal grid gap-10 lg:grid-cols-2 lg:items-end">
                <div>
                    <span class="eyebrow-light">@include('website.partials.icon', ['name' => 'shield', 'class' => 'size-3.5']) Safety, by design</span>
                    <h2 class="section-title text-white">Every trip has layers of protection.</h2>
                </div>
                <p class="text-lg leading-relaxed text-white/70">Safety isn’t a feature we bolted on — it’s why FemaleRider exists. Here’s what protects you on every ride.</p>
            </div>
            <ul class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['badge', 'Verified women drivers', 'Every driver’s identity, licence and vehicle documents are reviewed by a trained team member before she can go online.'],
                    ['key', 'Trip PIN', 'A 4-digit PIN confirms you’re getting into the right vehicle. The driver cannot start the trip without it.'],
                    ['share', 'Live trip sharing', 'Send a private link so family or friends can follow your ride on a map until you arrive.'],
                    ['sos', 'In-app SOS', 'One tap alerts the FemaleRider safety team with your trip details and location, and texts your emergency contact if you’ve added one.'],
                    ['headset', 'Safety team', 'Our safety team reviews alerts and reports during support hours, and follows up on every incident.'],
                    ['star', 'Two-way ratings', 'Riders and drivers rate every trip, helping us keep the community respectful and safe.'],
                ] as [$ico, $t, $d])
                    <li class="reveal rounded-3xl border border-white/10 bg-white/[0.04] p-7 backdrop-blur transition hover:border-crimson-400/40 hover:bg-white/[0.07]">
                        <span class="flex size-12 items-center justify-center rounded-2xl bg-crimson-600/90 text-white">@include('website.partials.icon', ['name' => $ico, 'class' => 'size-6'])</span>
                        <h3 class="mt-5 text-lg font-bold text-white">{{ $t }}</h3>
                        <p class="mt-2 text-[15px] leading-relaxed text-white/65">{{ $d }}</p>
                    </li>
                @endforeach
            </ul>
            <div class="reveal mt-10 flex flex-col gap-4 rounded-3xl border border-crimson-400/30 bg-crimson-900/30 p-6 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm leading-relaxed text-white/80"><strong class="text-white">In an emergency, always call 15 first.</strong> FemaleRider’s safety tools support you, but they are not an emergency service and cannot guarantee a response time.</p>
                <a href="{{ route('website.safety') }}" class="btn-light shrink-0">Explore safety</a>
            </div>
        </div>
    </section>

    {{-- VEHICLE CATEGORIES --}}
    @if ($categories->isNotEmpty())
        <section id="rides" class="section scroll-mt-20">
            <div class="container-site">
                <div class="reveal flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <span class="eyebrow">Ride options</span>
                        <h2 class="section-title">A ride for every journey.</h2>
                        <p class="section-lead">From a quick bike hop to a comfortable sedan — all driven by verified women.</p>
                    </div>
                </div>
                <ul class="mt-14 grid gap-5 sm:grid-cols-2 {{ [1 => 'lg:grid-cols-2', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3'][$categories->count()] ?? 'lg:grid-cols-4' }}">
                    @foreach ($categories as $category)
                        @php
                            $ico = match (true) {
                                str_contains($category->icon ?? '', 'bike') => 'bike',
                                str_contains($category->icon ?? '', 'rickshaw') => 'rickshaw',
                                default => 'car',
                            };
                        @endphp
                        <li class="reveal card group flex flex-col transition hover:-translate-y-1 hover:border-crimson-200 hover:shadow-lift">
                            <div class="flex items-start justify-between">
                                <span class="flex size-14 items-center justify-center rounded-2xl bg-crimson-50 text-crimson-700 transition group-hover:bg-crimson-600 group-hover:text-white">@include('website.partials.icon', ['name' => $ico, 'class' => 'size-7'])</span>
                                <span class="inline-flex items-center gap-1 rounded-full bg-ink/5 px-2.5 py-1 text-xs font-semibold text-ink-soft">@include('website.partials.icon', ['name' => 'users', 'class' => 'size-3.5']) {{ $category->capacity }} {{ Str::plural('seat', $category->capacity) }}</span>
                            </div>
                            <h3 class="mt-6 text-xl font-bold">{{ $category->name }}</h3>
                            @if ($category->description)
                                <p class="mt-2 flex-1 text-[15px] leading-relaxed text-ink-soft">{{ $category->description }}</p>
                            @endif
                            <p class="mt-6 border-t border-ink/5 pt-5 text-sm text-ink-soft">From <span class="font-display text-2xl font-extrabold text-ink">PKR {{ number_format((float) $category->minimum_fare) }}</span></p>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-6 text-sm text-ink/55">“From” prices are the minimum fare per trip. Your actual fare depends on distance and time and is the price you agree with your driver before the ride.</p>
            </div>
        </section>
    @endif

    {{-- CITIES --}}
    @if ($cities->isNotEmpty())
        <section id="cities" class="pb-20 sm:pb-28">
            <div class="container-site">
                <div class="reveal overflow-hidden rounded-[2.5rem] border border-ink/5 bg-blush">
                    <div class="grid gap-10 p-8 sm:p-12 lg:grid-cols-5 lg:items-center">
                        <div class="lg:col-span-2">
                            <span class="eyebrow">@include('website.partials.icon', ['name' => 'pin', 'class' => 'size-3.5']) Where we ride</span>
                            <h2 class="section-title">Launching across Pakistan.</h2>
                            <p class="mt-4 text-ink-soft">We’re starting in {{ $cities->count() === 1 ? $cities->first()->name : $cities->pluck('name')->slice(0, -1)->join(', ').' and '.$cities->last()->name }}, with more cities to follow.</p>
                        </div>
                        <ul class="grid gap-4 sm:grid-cols-3 lg:col-span-3">
                            @foreach ($cities as $city)
                                <li class="group relative overflow-hidden rounded-3xl bg-white p-6 shadow-soft">
                                    <span aria-hidden="true" class="absolute -right-6 -bottom-8 font-display text-8xl font-extrabold text-crimson-50">{{ mb_substr($city->name, 0, 1) }}</span>
                                    <span class="relative flex size-10 items-center justify-center rounded-xl bg-crimson-600 text-white">@include('website.partials.icon', ['name' => 'pin'])</span>
                                    <h3 class="relative mt-5 text-xl font-bold">{{ $city->name }}</h3>
                                    <p class="relative mt-1 text-sm text-ink-soft">Pakistan · PKR</p>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @include('website.partials.driver-cta')

    {{-- FAQ PREVIEW --}}
    @if ($faqs->isNotEmpty())
        <section class="section">
            <div class="container-site grid gap-12 lg:grid-cols-12">
                <div class="reveal lg:col-span-4">
                    <span class="eyebrow">Questions</span>
                    <h2 class="section-title">Good to know.</h2>
                    <p class="mt-4 text-ink-soft">The essentials about riding with FemaleRider.</p>
                    <a href="{{ route('website.faq') }}" class="btn-ghost mt-8">See all FAQs @include('website.partials.icon', ['name' => 'arrow-right', 'class' => 'size-4'])</a>
                </div>
                <div class="reveal space-y-3 lg:col-span-8">
                    @foreach ($faqs as $faq)
                        @include('website.partials.faq-item', ['faq' => $faq])
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @include('website.partials.download-cta')
@endsection
