{{-- App store badges: apps are not yet published, so these are non-interactive "Coming soon" badges. --}}
<div class="flex flex-wrap gap-3" role="list" aria-label="Mobile apps">
    @foreach ([['apple', 'App Store'], ['play', 'Google Play']] as [$ico, $store])
        <div role="listitem" class="inline-flex min-w-[190px] items-center gap-3 rounded-2xl border {{ ($dark ?? false) ? 'border-white/20 bg-white/10 text-white' : 'border-ink/10 bg-ink text-white' }} px-4 py-2.5" aria-label="{{ $store }} app — coming soon">
            @include('website.partials.icon', ['name' => $ico, 'class' => 'size-7'])
            <span class="flex flex-col leading-tight">
                <span class="text-[11px] font-medium uppercase tracking-wider text-white/65">Coming soon on</span>
                <span class="text-base font-semibold">{{ $store }}</span>
            </span>
        </div>
    @endforeach
</div>
