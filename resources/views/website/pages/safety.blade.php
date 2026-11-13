@extends('website.layouts.app')

@section('title', 'Safety')
@section('description', 'How FemaleRider keeps rides safe: women-only verified community, trip PIN, live trip sharing, in-app SOS and a safety team during support hours. In emergencies call 15.')

@section('content')
    @php($support = config('femalerider.support', []))

    @include('website.partials.page-hero', [
        'eyebrow' => 'Safety at FemaleRider',
        'icon' => 'shield',
        'title' => 'Safety is the reason we exist.',
        'lead' => 'From who can join to how every trip starts and ends, FemaleRider is designed around one question: would you feel comfortable taking this ride?',
    ])

    <div class="border-b border-crimson-100 bg-crimson-50">
        <div class="container-site flex flex-col gap-2 py-5 sm:flex-row sm:items-center sm:gap-4">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-crimson-600 text-white">@include('website.partials.icon', ['name' => 'phone'])</span>
            <p class="text-sm leading-relaxed text-crimson-900"><strong>In an emergency or if you are in immediate danger, call <a href="tel:15" class="underline underline-offset-2">15</a> (Police).</strong> FemaleRider is not an emergency service and cannot guarantee a response time.</p>
        </div>
    </div>

    {{-- Before / during / after --}}
    <section class="section">
        <div class="container-site">
            <div class="reveal mx-auto max-w-2xl text-center">
                <span class="eyebrow">Every step of the way</span>
                <h2 class="section-title">Before, during and after your ride.</h2>
            </div>
            <div class="mt-16 grid gap-6 lg:grid-cols-3">
                @foreach ([
                    ['Before', 'badge', [
                        ['Women-only community', 'Every rider and driver verifies her phone and passes an eligibility review by a trained person — we don’t use automated gender detection or face analysis.'],
                        ['Driver document checks', 'CNIC, driving licence, vehicle registration and photos are reviewed before a driver can go online.'],
                        ['See who’s coming', 'Before you accept an offer you see the driver’s first name, rating, vehicle and ETA.'],
                    ]],
                    ['During', 'navigation', [
                        ['Trip PIN', 'Check the plate and driver match the app, then share your 4-digit PIN. No PIN, no trip.'],
                        ['Live trip sharing', 'Share a private link with family or friends. They can follow the driver on a map without installing the app — the link expires automatically.'],
                        ['In-app SOS', 'Alerts our safety team with your trip and location, and sends an SMS to your emergency contact if you’ve set one.'],
                    ]],
                    ['After', 'star', [
                        ['Rate every trip', 'Two-way ratings help keep the community respectful. Consistently low ratings are reviewed.'],
                        ['Report an issue', 'Report a safety concern from your trip history. Reports are reviewed by our safety team during support hours.'],
                        ['Private by default', 'Phone numbers aren’t shown to the other party and trip data is kept only as long as needed.'],
                    ]],
                ] as [$phase, $ico, $items])
                    <div class="reveal card relative overflow-hidden !p-8">
                        <div class="flex items-center gap-3">
                            <span class="flex size-12 items-center justify-center rounded-2xl bg-crimson-600 text-white">@include('website.partials.icon', ['name' => $ico, 'class' => 'size-6'])</span>
                            <h3 class="text-2xl font-extrabold">{{ $phase }}</h3>
                        </div>
                        <ul class="mt-8 space-y-6">
                            @foreach ($items as [$t, $d])
                                <li class="flex gap-3">
                                    <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-crimson-50 text-crimson-700">@include('website.partials.icon', ['name' => 'check', 'class' => 'size-3.5', 'stroke' => '3'])</span>
                                    <div>
                                        <p class="font-semibold text-ink">{{ $t }}</p>
                                        <p class="mt-1 text-[15px] leading-relaxed text-ink-soft">{{ $d }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Safety team --}}
    <section class="section bg-ink text-white">
        <div class="container-site grid items-center gap-12 lg:grid-cols-2">
            <div class="reveal">
                <span class="eyebrow-light">@include('website.partials.icon', ['name' => 'headset', 'class' => 'size-3.5']) Safety team</span>
                <h2 class="section-title text-white">Real people reviewing every alert.</h2>
                <p class="mt-5 text-lg leading-relaxed text-white/70">When you press SOS or report an incident, it reaches the FemaleRider safety team with your trip details. Our team monitors alerts during support hours{{ ! empty($support['hours']) ? ' ('.$support['hours'].')' : '' }}, contacts the people involved, and can suspend accounts while we investigate.</p>
                <p class="mt-4 text-lg leading-relaxed text-white/70">Outside support hours, alerts are still recorded and your emergency contact is still notified by SMS if you’ve added one — but please always call <a href="tel:15" class="font-semibold text-white underline underline-offset-2">15</a> if you need urgent help.</p>
            </div>
            <div class="reveal rounded-[2rem] border border-white/10 bg-white/[0.04] p-8">
                <h3 class="text-lg font-bold text-white">Quick safety checklist</h3>
                <ol class="mt-6 space-y-4">
                    @foreach ([
                        'Match the plate number and driver name with the app.',
                        'Share your PIN only once you’re sure — never before.',
                        'Share your trip with someone you trust.',
                        'Sit in the back seat when travelling by car.',
                        'Add an emergency contact in your profile.',
                        'Trust your instincts — you can cancel and report.',
                    ] as $i => $tip)
                        <li class="flex gap-4">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-crimson-600 font-display text-sm font-bold">{{ $i + 1 }}</span>
                            <span class="pt-1 text-white/80">{{ $tip }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </section>

    {{-- For drivers --}}
    <section class="section">
        <div class="container-site grid gap-12 lg:grid-cols-2 lg:items-center">
            <div class="reveal">
                <span class="eyebrow">For drivers</span>
                <h2 class="section-title">Drivers are protected too.</h2>
                <p class="section-lead">Every passenger is a verified woman. Drivers see the pickup area and fare before accepting, can decline any request, and have the same SOS and support tools as riders.</p>
                <a href="{{ route('website.drive') }}" class="btn-primary mt-8">Drive with FemaleRider</a>
            </div>
            <ul class="reveal grid gap-4 sm:grid-cols-2">
                @foreach ([['users', 'Verified riders only'], ['eye', 'Fare & area shown upfront'], ['sos', 'Driver SOS'], ['lock', 'Private phone numbers']] as [$ico, $t])
                    <li class="flex items-center gap-4 rounded-2xl border border-ink/10 p-5">
                        <span class="flex size-11 items-center justify-center rounded-xl bg-crimson-50 text-crimson-700">@include('website.partials.icon', ['name' => $ico])</span>
                        <span class="font-semibold">{{ $t }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="pb-20 sm:pb-28">
        <div class="container-site">
            <div class="reveal flex flex-col items-start gap-6 rounded-[2rem] bg-blush p-8 sm:flex-row sm:items-center sm:justify-between sm:p-10">
                <div>
                    <h2 class="text-2xl font-extrabold">Have a safety concern?</h2>
                    <p class="mt-2 text-ink-soft">Report it from the app, or contact us. For emergencies, call 15.</p>
                </div>
                <a href="{{ route('website.contact') }}" class="btn-primary shrink-0">Contact the team</a>
            </div>
        </div>
    </section>
@endsection
