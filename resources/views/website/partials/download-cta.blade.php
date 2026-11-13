<section id="download" class="section scroll-mt-20">
    <div class="container-site">
        <div class="reveal relative isolate overflow-hidden rounded-[2.5rem] bg-brand-gradient grain px-7 py-14 text-white shadow-lift sm:px-14 sm:py-20">
            <div aria-hidden="true" class="pointer-events-none absolute -top-24 -left-24 size-80 rounded-full bg-white/10 blur-3xl"></div>
            <div class="relative grid items-center gap-12 lg:grid-cols-2">
                <div>
                    <span class="eyebrow-light">@include('website.partials.icon', ['name' => 'smartphone', 'class' => 'size-3.5']) The FemaleRider app</span>
                    <h2 class="mt-5 text-3xl font-extrabold leading-tight text-white sm:text-4xl lg:text-5xl">Your city, on your terms.<br class="hidden sm:block"> Coming soon to your phone.</h2>
                    <p class="mt-5 max-w-xl text-lg text-white/80">One app for riders and drivers. We're putting the finishing touches on FemaleRider for Android and iPhone — launching first in Karachi, Lahore and Islamabad.</p>
                    <div class="mt-8">@include('website.partials.store-badges', ['dark' => true])</div>
                    <p class="mt-5 text-sm text-white/65">Want to hear the moment we launch? <a href="{{ route('website.contact') }}" class="font-semibold text-white underline underline-offset-4">Leave us a message</a>.</p>
                </div>
                <div class="relative mx-auto hidden w-full max-w-sm lg:block" aria-hidden="true">
                    @include('website.partials.phone-mock')
                </div>
            </div>
        </div>
    </div>
</section>
