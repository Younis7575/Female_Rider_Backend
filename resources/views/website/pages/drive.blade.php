@extends('website.layouts.app')

@section('title', 'Drive with us')
@section('description', 'Drive with FemaleRider: earn on your schedule with women-only passengers. Requirements: CNIC, driving licence, vehicle registration and photos. Commission 12–18% by vehicle category, disclosed upfront.')

@section('content')
    @php
        $fmtPct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $commissionRange = ($commissionMin !== null && $commissionMax !== null)
            ? ($commissionMin == $commissionMax ? $fmtPct($commissionMin).'%' : $fmtPct($commissionMin).'–'.$fmtPct($commissionMax).'%')
            : '12–18%';
    @endphp

    <section class="relative isolate overflow-hidden bg-brand-gradient grain text-white">
        <div aria-hidden="true" class="pointer-events-none absolute -right-24 -bottom-32 size-[420px] rounded-full bg-rose-accent/20 blur-3xl"></div>
        <div class="container-site relative grid items-center gap-12 py-20 sm:py-24 lg:grid-cols-12 lg:py-28">
            <div class="lg:col-span-7">
                <span class="eyebrow-light">@include('website.partials.icon', ['name' => 'wallet', 'class' => 'size-3.5']) Drive with FemaleRider</span>
                <h1 class="mt-5 text-4xl leading-[1.08] font-extrabold text-white sm:text-5xl lg:text-6xl">Your car. Your hours.<br>Women-only passengers.</h1>
                <p class="mt-6 max-w-2xl text-lg leading-relaxed text-white/80">Earn on your own schedule driving verified women riders around your city. See the fare before you accept, counter if you like, and know exactly what you keep.</p>
                <div class="mt-9 flex flex-col gap-3 sm:flex-row">
                    <a href="#apply" class="btn-light !px-7 !py-3.5 text-base">How to apply</a>
                    <a href="#requirements" class="btn-ghost-light !px-7 !py-3.5 text-base">See requirements</a>
                </div>
            </div>
            <div class="lg:col-span-5">
                <div class="rounded-[2rem] border border-white/15 bg-white/10 p-7 backdrop-blur">
                    <p class="text-sm font-semibold tracking-wide text-rose-accent uppercase">Commission</p>
                    <p class="mt-2 font-display text-5xl font-extrabold">{{ $commissionRange }}</p>
                    <p class="mt-2 text-white/75">depending on vehicle category — the rest of the fare is yours. Shown on every trip’s earnings breakdown.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Benefits --}}
    <section class="section">
        <div class="container-site">
            <div class="reveal mx-auto max-w-2xl text-center">
                <span class="eyebrow">Why drive with us</span>
                <h2 class="section-title">Built for women who drive.</h2>
            </div>
            <ul class="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['users', 'Women-only passengers', 'Every rider has verified her phone and passed our eligibility review.'],
                    ['calendar', 'Total flexibility', 'Go online and offline whenever you like. No minimum hours.'],
                    ['scale', 'Fair, negotiable fares', 'See the rider’s offer upfront and accept, or counter with your own price.'],
                    ['trend', 'Transparent earnings', 'Commission is disclosed for your category and itemised on every trip.'],
                    ['wallet', 'Easy payouts', 'Request payouts to your bank, JazzCash or Easypaisa account from the app.'],
                    ['shield', 'Safety tools for you', 'Trip PIN, SOS and a support team during support hours — for drivers too.'],
                ] as [$ico, $t, $d])
                    <li class="reveal card">
                        <span class="flex size-12 items-center justify-center rounded-2xl bg-crimson-50 text-crimson-700">@include('website.partials.icon', ['name' => $ico, 'class' => 'size-6'])</span>
                        <h3 class="mt-5 text-lg font-bold">{{ $t }}</h3>
                        <p class="mt-2 text-[15px] leading-relaxed text-ink-soft">{{ $d }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Requirements --}}
    <section id="requirements" class="section scroll-mt-20 bg-blush">
        <div class="container-site grid gap-12 lg:grid-cols-12">
            <div class="reveal lg:col-span-5">
                <span class="eyebrow">Requirements</span>
                <h2 class="section-title">What you’ll need.</h2>
                <p class="mt-5 text-lg leading-relaxed text-ink-soft">Have these ready and you can complete your application in the app in a few minutes. Documents are stored privately and viewed only by authorised reviewers.</p>
                <p class="mt-4 text-sm text-ink/60">You must be at least 18 years old and meet our <a href="{{ route('website.legal', 'eligibility-policy') }}" class="font-semibold text-crimson-700 underline underline-offset-2">eligibility policy</a>.</p>
            </div>
            <ul class="reveal grid gap-4 sm:grid-cols-2 lg:col-span-7">
                @foreach ([
                    ['id', 'CNIC', 'Front and back of your valid Computerised National Identity Card.'],
                    ['doc', 'Driving licence', 'Front and back of a valid licence for your vehicle type.'],
                    ['car', 'Vehicle registration', 'Registration documents for the vehicle you’ll drive.'],
                    ['camera', 'Photos', 'A clear profile photo of you and photos of your vehicle.'],
                ] as [$ico, $t, $d])
                    <li class="flex gap-4 rounded-3xl bg-white p-6 shadow-soft">
                        <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-crimson-600 text-white">@include('website.partials.icon', ['name' => $ico, 'class' => 'size-6'])</span>
                        <div>
                            <h3 class="text-lg font-bold">{{ $t }}</h3>
                            <p class="mt-1 text-[15px] leading-relaxed text-ink-soft">{{ $d }}</p>
                        </div>
                    </li>
                @endforeach
                <li class="rounded-3xl border border-dashed border-crimson-200 bg-white/60 p-6 text-[15px] text-ink-soft sm:col-span-2">
                    <strong class="text-ink">Recommended:</strong> vehicle insurance and fitness certificate. These help your application and keep you covered.
                </li>
            </ul>
        </div>
    </section>

    {{-- Commission table --}}
    @if ($categories->isNotEmpty())
        <section class="section">
            <div class="container-site">
                <div class="reveal max-w-2xl">
                    <span class="eyebrow">Commission disclosure</span>
                    <h2 class="section-title">Know exactly what you keep.</h2>
                    <p class="section-lead">FemaleRider charges a commission on each completed trip fare, set by vehicle category. Current rates:</p>
                </div>
                <div class="reveal mt-10 overflow-hidden rounded-3xl border border-ink/10">
                    <table class="w-full text-left text-sm sm:text-base">
                        <caption class="sr-only">Commission by vehicle category</caption>
                        <thead class="bg-ink text-white">
                            <tr>
                                <th scope="col" class="px-5 py-4 font-semibold sm:px-7">Category</th>
                                <th scope="col" class="hidden px-5 py-4 font-semibold sm:table-cell">Seats</th>
                                <th scope="col" class="px-5 py-4 font-semibold">Commission</th>
                                <th scope="col" class="px-5 py-4 text-right font-semibold sm:px-7">You keep</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink/5 bg-white">
                            @foreach ($categories as $category)
                                <tr>
                                    <th scope="row" class="px-5 py-4 font-semibold text-ink sm:px-7">{{ $category->name }}</th>
                                    <td class="hidden px-5 py-4 text-ink-soft sm:table-cell">{{ $category->capacity }}</td>
                                    <td class="px-5 py-4 text-ink-soft">{{ $fmtPct($category->commission_percent) }}%</td>
                                    <td class="px-5 py-4 text-right font-bold text-crimson-700 sm:px-7">{{ $fmtPct(100 - (float) $category->commission_percent) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-4 text-sm text-ink/55">The rate applied to each trip is always shown in your trip earnings. See the <a href="{{ route('website.legal', 'driver-agreement') }}" class="font-medium text-crimson-700 underline underline-offset-2">driver agreement</a> for full terms.</p>
            </div>
        </section>
    @endif

    {{-- Steps --}}
    <section id="apply" class="section scroll-mt-20 bg-ink text-white">
        <div class="container-site">
            <div class="reveal mx-auto max-w-2xl text-center">
                <span class="eyebrow-light">How to apply</span>
                <h2 class="section-title text-white">From sign-up to first ride.</h2>
            </div>
            <ol class="relative mt-16 grid gap-8 md:grid-cols-2 lg:grid-cols-5">
                @foreach ([
                    ['smartphone', 'Get the app', 'Download FemaleRider and sign up with your mobile number.'],
                    ['id', 'Verify yourself', 'Complete the eligibility review with your CNIC.'],
                    ['doc', 'Add documents', 'Upload your licence, registration and photos.'],
                    ['check-circle', 'Get approved', 'Our team reviews your application and lets you know.'],
                    ['navigation', 'Go online', 'Switch on, see nearby requests and start earning.'],
                ] as $i => [$ico, $t, $d])
                    <li class="reveal relative">
                        <div class="flex items-center gap-3">
                            <span class="flex size-12 items-center justify-center rounded-2xl bg-crimson-600 text-white">@include('website.partials.icon', ['name' => $ico, 'class' => 'size-6'])</span>
                            <span class="font-display text-sm font-bold tracking-widest text-white/40">0{{ $i + 1 }}</span>
                        </div>
                        <h3 class="mt-5 text-lg font-bold text-white">{{ $t }}</h3>
                        <p class="mt-2 text-[15px] leading-relaxed text-white/65">{{ $d }}</p>
                    </li>
                @endforeach
            </ol>
            @if ($cities->isNotEmpty())
                <p class="reveal mt-14 text-center text-white/60">Now onboarding drivers in <span class="font-semibold text-white">{{ $cities->pluck('name')->join(', ', ' and ') }}</span>.</p>
            @endif
        </div>
    </section>

    @include('website.partials.download-cta')
@endsection
