@extends('website.layouts.app')

@section('title', 'Live trip')
@section('description', 'Follow a FemaleRider trip shared with you.')
@section('robots', 'noindex, nofollow, noarchive')
@section('minimal', '1')

@section('header-right')
    <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 ring-1 ring-emerald-200">
        <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500 opacity-70"></span><span class="relative inline-flex size-2 rounded-full bg-emerald-600"></span></span>
        Live
    </span>
@endsection

@push('head')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
@endpush

@push('scripts')
    <script type="application/json" id="trip-data">{!! json_encode(['statusUrl' => route('website.trip.status', $token), 'initial' => $initial], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
    @vite('resources/js/trip-share.js')
@endpush

@section('content')
    @php
        $steps = [
            'driver_assigned' => 'Assigned',
            'driver_en_route' => 'On the way',
            'arrived' => 'At pickup',
            'in_progress' => 'In progress',
        ];
        $order = array_keys($steps);
        $currentIndex = array_search($initial['status'], $order, true);
    @endphp

    <div class="relative flex flex-col lg:h-[calc(100dvh-72px)] lg:flex-row">
        {{-- Map --}}
        <div class="relative h-[55vh] min-h-[340px] bg-crimson-50 lg:h-full lg:min-h-0 lg:flex-1">
            <div id="trip-map" class="absolute inset-0 z-0" role="region" aria-label="Map showing the driver’s live location, pickup area and destination area"></div>
            <noscript>
                <div class="absolute inset-0 flex items-center justify-center p-6 text-center text-ink-soft">Turn on JavaScript to see the live map.</div>
            </noscript>
            <button type="button" data-recenter class="absolute top-4 right-4 z-[400] inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-semibold text-ink shadow-soft hover:text-crimson-700">
                @include('website.partials.icon', ['name' => 'navigation', 'class' => 'size-4']) Recenter
            </button>
            <div data-connection class="absolute top-4 left-4 z-[400] hidden rounded-full bg-amber-50 px-4 py-2 text-sm font-medium text-amber-900 shadow-soft ring-1 ring-amber-200" role="status">Reconnecting…</div>
        </div>

        {{-- Details panel --}}
        <aside class="relative z-10 -mt-6 rounded-t-[2rem] bg-white shadow-[0_-12px_32px_-12px_rgb(20_16_20/0.18)] lg:mt-0 lg:w-[420px] lg:overflow-y-auto lg:rounded-none lg:border-l lg:border-ink/5 lg:shadow-none" aria-labelledby="trip-status-heading">
            <div class="mx-auto mt-3 h-1.5 w-12 rounded-full bg-ink/10 lg:hidden" aria-hidden="true"></div>
            <div class="space-y-6 p-6 sm:p-8">
                <div aria-live="polite">
                    <p class="text-xs font-semibold tracking-[0.14em] text-crimson-700 uppercase">Trip status</p>
                    <h1 id="trip-status-heading" class="mt-1.5 text-2xl font-extrabold sm:text-3xl" data-field="status_label">{{ $initial['status_label'] }}</h1>
                    <p class="mt-1.5 text-ink-soft" data-field="status_message">{{ $initial['status_message'] }}</p>
                </div>

                <ol class="grid grid-cols-4 gap-2" aria-label="Trip progress">
                    @foreach ($steps as $key => $label)
                        @php($reached = $currentIndex !== false && array_search($key, $order, true) <= $currentIndex)
                        <li data-step="{{ $key }}" data-reached="{{ $reached ? 'true' : 'false' }}" @if ($key === $initial['status']) aria-current="step" @endif class="group">
                            <span class="block h-1.5 rounded-full bg-ink/10 group-data-[reached=true]:bg-crimson-600"></span>
                            <span class="mt-2 block text-[11px] font-semibold text-ink/45 group-data-[reached=true]:text-ink sm:text-xs">{{ $label }}</span>
                        </li>
                    @endforeach
                </ol>

                <div class="rounded-3xl border border-ink/10 p-5">
                    <div class="flex items-center gap-4">
                        <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-crimson-600 font-display text-xl font-extrabold text-white" aria-hidden="true">{{ mb_substr($initial['driver']['first_name'] ?? 'D', 0, 1) }}</span>
                        <div class="min-w-0">
                            <p class="text-xs font-medium text-ink/50">Driver</p>
                            <p class="truncate text-lg font-bold" data-field="driver_first_name">{{ $initial['driver']['first_name'] }}</p>
                            <p class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700">@include('website.partials.icon', ['name' => 'badge', 'class' => 'size-3.5']) Verified FemaleRider driver</p>
                        </div>
                    </div>
                    <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-ink/5 pt-5">
                        <div class="min-w-0">
                            <dt class="text-xs font-medium text-ink/50">Vehicle</dt>
                            <dd class="mt-0.5 font-semibold" data-field="vehicle_label">{{ $initial['vehicle']['label'] ?? $initial['vehicle']['category'] ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium text-ink/50">Plate</dt>
                            <dd class="mt-1"><span class="inline-block rounded-lg border-2 border-ink bg-white px-2.5 py-0.5 font-mono text-sm font-bold tracking-wider" data-field="vehicle_plate">{{ $initial['vehicle']['plate_number'] ?? '—' }}</span></dd>
                        </div>
                    </dl>
                </div>

                <ol class="relative space-y-5 pl-8" aria-label="Route">
                    <span aria-hidden="true" class="absolute top-3 bottom-3 left-[11px] w-0.5 bg-gradient-to-b from-teal-700 to-crimson-800"></span>
                    <li class="relative">
                        <span aria-hidden="true" class="absolute top-0.5 -left-8 flex size-6 items-center justify-center rounded-full bg-teal-700 text-[11px] font-bold text-white ring-4 ring-white">A</span>
                        <p class="text-xs font-medium text-ink/50">Pickup area</p>
                        <p class="font-medium" data-field="pickup_address">{{ $initial['pickup']['address'] ?? '—' }}</p>
                    </li>
                    <li class="relative">
                        <span aria-hidden="true" class="absolute top-0.5 -left-8 flex size-6 items-center justify-center rounded-full bg-crimson-800 text-[11px] font-bold text-white ring-4 ring-white">B</span>
                        <p class="text-xs font-medium text-ink/50">Destination area</p>
                        <p class="font-medium" data-field="dropoff_address">{{ $initial['dropoff']['address'] ?? '—' }}</p>
                    </li>
                </ol>

                <div class="flex items-center justify-between gap-3 rounded-2xl bg-ink/[0.03] px-4 py-3 text-sm">
                    <span class="inline-flex items-center gap-2 text-ink-soft">@include('website.partials.icon', ['name' => 'clock', 'class' => 'size-4']) <span data-field="updated">{{ $initial['driver_location'] ? 'Updated just now' : 'No location yet' }}</span></span>
                    <span data-stale class="{{ ($initial['driver_location']['stale'] ?? true) ? '' : 'hidden' }} rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-800">Waiting for signal</span>
                </div>

                <div class="rounded-2xl border border-crimson-200 bg-crimson-50 p-4 text-sm text-crimson-900">
                    <p><strong>Worried about this trip?</strong> If you believe someone is in danger, call <a href="tel:15" class="font-bold underline underline-offset-2">15</a> immediately.</p>
                    @if (config('femalerider.support.phone'))
                        <p class="mt-2">You can also reach FemaleRider support during support hours on <a href="tel:{{ preg_replace('/[^0-9+]/', '', config('femalerider.support.phone')) }}" class="font-semibold underline underline-offset-2">{{ config('femalerider.support.phone') }}</a>.</p>
                    @endif
                </div>

                <p class="text-xs leading-relaxed text-ink/50">This private link was shared by a FemaleRider rider and stops working when the trip ends. Map data &copy; OpenStreetMap contributors.</p>
            </div>
        </aside>
    </div>
@endsection
