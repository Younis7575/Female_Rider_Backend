@extends('website.layouts.app')

@section('title', 'About us')
@section('description', 'FemaleRider is a women-only ride-hailing platform built in Pakistan by Gopang IT Solution — so women can move through their cities with confidence, dignity and fair fares.')

@section('content')
    @include('website.partials.page-hero', [
        'eyebrow' => 'About FemaleRider',
        'icon' => 'heart',
        'title' => 'Moving women forward, one ride at a time.',
        'lead' => 'FemaleRider is a women-only ride-hailing platform built in Pakistan, for Pakistan — so women can get to work, class, family and everything else with confidence.',
    ])

    <section class="section">
        <div class="container-site grid gap-14 lg:grid-cols-12">
            <div class="reveal lg:col-span-5">
                <span class="eyebrow">Our story</span>
                <h2 class="section-title">Why we built FemaleRider.</h2>
            </div>
            <div class="reveal space-y-5 text-lg leading-relaxed text-ink-soft lg:col-span-7">
                <p>For many women, getting from A to B means planning around who will be driving, whether a family member is free to come along, or whether it’s worth going at all. That shouldn’t be the case.</p>
                <p>FemaleRider is a community where every rider and every driver is a woman who has been verified by our team. Riders name a price they think is fair; drivers decide which rides to take and can counter. Both sides see the details before they commit.</p>
                <p>We’re also creating flexible, respectful earning opportunities for women drivers — with transparent commission and riders they can feel comfortable with.</p>
            </div>
        </div>
    </section>

    <section class="section bg-blush">
        <div class="container-site">
            <div class="reveal mx-auto max-w-2xl text-center">
                <span class="eyebrow">What we stand for</span>
                <h2 class="section-title">Our principles</h2>
            </div>
            <ul class="mt-14 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['shield', 'Safety first', 'Verification, trip PINs, live sharing and SOS are part of every ride — not an upgrade.'],
                    ['scale', 'Fairness', 'Riders propose fares within a fair range and drivers can counter. Commission is disclosed, never hidden.'],
                    ['eye', 'Privacy', 'Phone numbers stay private, documents are seen only by authorised reviewers, and we collect only what we need.'],
                    ['heart', 'Dignity', 'A respectful community where women look out for women — backed by ratings and a real support team.'],
                ] as [$ico, $t, $d])
                    <li class="reveal card">
                        <span class="flex size-12 items-center justify-center rounded-2xl bg-crimson-600 text-white">@include('website.partials.icon', ['name' => $ico, 'class' => 'size-6'])</span>
                        <h3 class="mt-5 text-lg font-bold">{{ $t }}</h3>
                        <p class="mt-2 text-[15px] leading-relaxed text-ink-soft">{{ $d }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="section">
        <div class="container-site grid items-center gap-12 lg:grid-cols-2">
            <div class="reveal">
                <span class="eyebrow">Who we are</span>
                <h2 class="section-title">Built by Gopang IT Solution.</h2>
                <p class="section-lead">FemaleRider is developed and operated by Gopang IT Solution, a Pakistani technology company. We’re launching first in
                    @if ($cities->isNotEmpty())
                        {{ $cities->count() === 1 ? $cities->first()->name : $cities->pluck('name')->slice(0, -1)->join(', ').' and '.$cities->last()->name }},
                    @else
                        major Pakistani cities,
                    @endif
                    with fares in Pakistani rupees and support from a local team.</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('website.contact') }}" class="btn-primary">Get in touch</a>
                    <a href="{{ route('website.legal', 'eligibility-policy') }}" class="btn-ghost">Our eligibility policy</a>
                </div>
            </div>
            <div class="reveal grid grid-cols-2 gap-4">
                @foreach ([['users', 'Women-only', 'riders & drivers'], ['pin', $cities->count() ?: 3, 'launch cities'], ['tag', 'PKR', 'fares you agree'], ['key', '4-digit', 'trip PIN on every ride']] as [$ico, $big, $small])
                    <div class="rounded-3xl bg-ink p-6 text-white {{ $loop->odd ? 'translate-y-4' : '' }}">
                        <span class="text-crimson-400">@include('website.partials.icon', ['name' => $ico, 'class' => 'size-6'])</span>
                        <p class="mt-6 font-display text-3xl font-extrabold">{{ $big }}</p>
                        <p class="mt-1 text-sm text-white/60">{{ $small }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    @include('website.partials.download-cta')
@endsection
