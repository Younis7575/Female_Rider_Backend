<details class="faq-item group rounded-2xl border border-ink/10 bg-white transition open:border-crimson-200 open:shadow-soft">
    <summary class="flex cursor-pointer list-none items-start justify-between gap-6 rounded-2xl px-6 py-5 text-left font-semibold text-ink hover:text-crimson-700 focus-visible:outline-2 focus-visible:outline-crimson-600">
        <span>{{ $faq->question }}</span>
        <span class="faq-icon mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-crimson-50 text-crimson-700 transition-transform duration-200">@include('website.partials.icon', ['name' => 'plus', 'class' => 'size-4', 'stroke' => '2.4'])</span>
    </summary>
    <div class="px-6 pb-6 text-[15px] leading-relaxed whitespace-pre-line text-ink-soft">{{ $faq->answer }}</div>
</details>
