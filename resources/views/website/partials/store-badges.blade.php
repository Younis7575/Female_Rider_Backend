{{-- Android: direct APK download (live). iPhone: coming soon. --}}
<div class="flex flex-wrap gap-3" role="list" aria-label="Mobile apps">
    <a role="listitem" href="{{ route('website.download.android') }}" class="inline-flex min-w-[190px] items-center gap-3 rounded-2xl border {{ ($dark ?? false) ? 'border-white/30 bg-white text-ink' : 'border-ink/10 bg-ink text-white' }} px-4 py-2.5 shadow-lift transition hover:-translate-y-0.5" aria-label="Download the FemaleRider app for Android">
        @include('website.partials.icon', ['name' => 'play', 'class' => 'size-7'])
        <span class="flex flex-col leading-tight">
            <span class="text-[11px] font-medium uppercase tracking-wider opacity-65">Download now for</span>
            <span class="text-base font-semibold">Android</span>
        </span>
    </a>
    <div role="listitem" class="inline-flex min-w-[190px] items-center gap-3 rounded-2xl border {{ ($dark ?? false) ? 'border-white/20 bg-white/10 text-white' : 'border-ink/10 bg-ink/80 text-white' }} px-4 py-2.5" aria-label="App Store app — coming soon">
        @include('website.partials.icon', ['name' => 'apple', 'class' => 'size-7'])
        <span class="flex flex-col leading-tight">
            <span class="text-[11px] font-medium uppercase tracking-wider text-white/65">Coming soon on</span>
            <span class="text-base font-semibold">App Store</span>
        </span>
    </div>
</div>
