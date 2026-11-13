{{-- Decorative phone illustration (no real data). --}}
<div class="relative">
    <div class="absolute -inset-6 rounded-[3rem] bg-white/10 blur-2xl"></div>
    <div class="relative animate-float rounded-[2.75rem] border-[10px] border-ink bg-ink shadow-2xl">
        <div class="overflow-hidden rounded-[2rem] bg-white">
            <div class="relative h-52 bg-crimson-50">
                <svg viewBox="0 0 320 210" class="absolute inset-0 h-full w-full" preserveAspectRatio="xMidYMid slice">
                    <defs><pattern id="g" width="32" height="32" patternUnits="userSpaceOnUse"><path d="M32 0H0v32" fill="none" stroke="#ffc7ce" stroke-width="1"/></pattern></defs>
                    <rect width="320" height="210" fill="url(#g)"/>
                    <path d="M-10 160 C 60 150, 80 90, 150 100 S 260 40, 330 50" stroke="#fff" stroke-width="16" fill="none"/>
                    <path d="M60 175 C 100 140, 140 120, 170 105 S 230 70, 255 62" stroke="#c8102e" stroke-width="4" stroke-dasharray="2 8" stroke-linecap="round" fill="none"/>
                    <circle cx="60" cy="175" r="9" fill="#0f766e" stroke="#fff" stroke-width="3"/>
                    <circle cx="255" cy="62" r="9" fill="#7a0a12" stroke="#fff" stroke-width="3"/>
                    <circle cx="150" cy="112" r="14" fill="#c8102e" stroke="#fff" stroke-width="4"/>
                </svg>
            </div>
            <div class="space-y-3 p-5 text-ink">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-medium text-ink/50">Your offer</p>
                        <p class="font-display text-2xl font-extrabold">PKR 450</p>
                    </div>
                    <span class="rounded-full bg-crimson-50 px-3 py-1 text-xs font-semibold text-crimson-700">3 offers</span>
                </div>
                @foreach ([['Ayesha', '4.9', '480', '3 min'], ['Sana', '5.0', '450', '5 min']] as [$n, $r, $p, $eta])
                    <div class="flex items-center gap-3 rounded-2xl border border-ink/10 p-3">
                        <span class="flex size-9 items-center justify-center rounded-full bg-crimson-600 text-sm font-bold text-white">{{ $n[0] }}</span>
                        <div class="flex-1">
                            <p class="text-sm font-semibold">{{ $n }} <span class="text-xs font-medium text-ink/50">★ {{ $r }}</span></p>
                            <p class="text-xs text-ink/50">{{ $eta }} away</p>
                        </div>
                        <span class="text-sm font-bold">PKR {{ $p }}</span>
                    </div>
                @endforeach
                <div class="rounded-2xl bg-crimson-600 py-3 text-center text-sm font-semibold text-white">Choose Sana</div>
            </div>
        </div>
    </div>
</div>
