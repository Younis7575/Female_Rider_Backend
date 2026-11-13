<section class="section bg-blush">
    <div class="container-site">
        <div class="reveal grid items-center gap-12 lg:grid-cols-2">
            <div>
                <span class="eyebrow">@include('website.partials.icon', ['name' => 'wallet', 'class' => 'size-3.5']) Drive with FemaleRider</span>
                <h2 class="section-title">Earn on your schedule, <span class="text-gradient">among women only.</span></h2>
                <p class="section-lead">Every passenger is a verified woman. You see the fare before you accept, choose the rides you want, and keep the majority of every fare — with commission disclosed upfront.</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('website.drive') }}" class="btn-primary">Become a driver @include('website.partials.icon', ['name' => 'arrow-right', 'class' => 'size-4'])</a>
                    <a href="{{ route('website.legal', 'driver-agreement') }}" class="btn-ghost">Read the driver agreement</a>
                </div>
            </div>
            <ul class="grid gap-4 sm:grid-cols-2">
                @foreach ([
                    ['calendar', 'Flexible hours', 'Go online when it suits you — mornings, school runs or weekends.'],
                    ['users', 'Women-only riders', 'Every passenger has passed phone verification and eligibility review.'],
                    ['scale', 'You can counter', 'See the rider’s offer and accept it or send your own price.'],
                    ['trend', 'Clear earnings', 'Commission from 12–18% by category, shown on every trip.'],
                ] as [$ico, $t, $d])
                    <li class="card !p-6">
                        <span class="flex size-11 items-center justify-center rounded-2xl bg-crimson-600 text-white">@include('website.partials.icon', ['name' => $ico])</span>
                        <h3 class="mt-4 text-lg font-bold">{{ $t }}</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-ink-soft">{{ $d }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</section>
