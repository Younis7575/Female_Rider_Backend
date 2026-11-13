{{-- Inner-page hero. Vars: $eyebrow, $title, $lead, optional $icon --}}
<section class="relative isolate overflow-hidden bg-brand-gradient grain text-white">
    <div aria-hidden="true" class="pointer-events-none absolute -right-24 -bottom-32 size-[420px] rounded-full bg-rose-accent/20 blur-3xl"></div>
    <div class="container-site relative py-20 sm:py-24 lg:py-28">
        <div class="max-w-3xl">
            @isset($eyebrow)
                <span class="eyebrow-light">
                    @isset($icon) @include('website.partials.icon', ['name' => $icon, 'class' => 'size-3.5']) @endisset
                    {{ $eyebrow }}
                </span>
            @endisset
            <h1 class="mt-5 text-4xl font-extrabold leading-[1.08] text-white sm:text-5xl lg:text-6xl">{{ $title }}</h1>
            @isset($lead)
                <p class="mt-6 max-w-2xl text-lg leading-relaxed text-white/80">{{ $lead }}</p>
            @endisset
        </div>
    </div>
</section>
