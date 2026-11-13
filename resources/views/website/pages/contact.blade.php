@extends('website.layouts.app')

@section('title', 'Contact us')
@section('description', 'Contact the FemaleRider team — rider and driver support, safety concerns, partnerships and privacy requests.')

@section('content')
    @php($support = config('femalerider.support', []))

    @include('website.partials.page-hero', [
        'eyebrow' => 'Contact',
        'icon' => 'mail',
        'title' => 'We’d love to hear from you.',
        'lead' => 'Questions, feedback, partnership ideas or a safety concern — send us a message and our team will get back to you during support hours.',
    ])

    <section class="section">
        <div class="container-site grid gap-12 lg:grid-cols-12">
            <div class="lg:col-span-7">
                @if (session('status'))
                    <div role="status" class="mb-8 flex gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-emerald-900">
                        @include('website.partials.icon', ['name' => 'check-circle', 'class' => 'size-6 shrink-0 text-emerald-600'])
                        <p class="font-medium">{{ session('status') }}</p>
                    </div>
                @endif

                @if ($errors->any())
                    <div role="alert" class="mb-8 rounded-2xl border border-crimson-200 bg-crimson-50 p-5 text-crimson-900">
                        <p class="font-semibold">Please check the form — {{ $errors->count() === 1 ? 'one field needs' : $errors->count().' fields need' }} your attention.</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('website.contact.store') }}" class="card relative !p-7 sm:!p-10" novalidate data-once>
                    @csrf
                    {{-- Honeypot: hidden from people and assistive tech; bots tend to fill it. --}}
                    <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                        <label for="{{ $honeypot }}">Leave this field empty</label>
                        <input type="text" id="{{ $honeypot }}" name="{{ $honeypot }}" value="" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="name" class="field-label">Your name</label>
                            <input id="name" name="name" type="text" class="field" value="{{ old('name') }}" required maxlength="100" autocomplete="name" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                            @error('name')<p id="name-error" class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="email" class="field-label">Email <span class="font-normal text-ink/50">(or phone)</span></label>
                            <input id="email" name="email" type="email" class="field" value="{{ old('email') }}" maxlength="150" autocomplete="email" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                            @error('email')<p id="email-error" class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="phone" class="field-label">Phone <span class="font-normal text-ink/50">(or email)</span></label>
                            <input id="phone" name="phone" type="tel" class="field" value="{{ old('phone') }}" maxlength="20" autocomplete="tel" placeholder="+92 3xx xxxxxxx" @error('phone') aria-invalid="true" aria-describedby="phone-error" @enderror>
                            @error('phone')<p id="phone-error" class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="subject" class="field-label">Topic</label>
                            <select id="subject" name="subject" class="field" required @error('subject') aria-invalid="true" aria-describedby="subject-error" @enderror>
                                <option value="" disabled @selected(! old('subject'))>Choose a topic…</option>
                                @foreach ($subjects as $value => $label)
                                    <option value="{{ $value }}" @selected(old('subject') === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('subject')<p id="subject-error" class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label for="message" class="field-label">Message</label>
                            <textarea id="message" name="message" rows="6" class="field resize-y" required minlength="10" maxlength="5000" @error('message') aria-invalid="true" aria-describedby="message-error" @enderror>{{ old('message') }}</textarea>
                            @error('message')<p id="message-error" class="field-error">{{ $message }}</p>@enderror
                            <p class="mt-2 text-xs text-ink/50">Please don’t include CNIC numbers, passwords or payment details.</p>
                        </div>
                    </div>
                    <div class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-ink/55">We use your details only to reply. See our <a href="{{ route('website.legal', 'privacy') }}" class="underline underline-offset-2">privacy policy</a>.</p>
                        <button type="submit" class="btn-primary shrink-0 !px-8">Send message</button>
                    </div>
                </form>
            </div>

            <aside class="space-y-5 lg:col-span-5">
                <div class="rounded-3xl border border-crimson-200 bg-crimson-50 p-7">
                    <div class="flex items-center gap-3">
                        <span class="flex size-10 items-center justify-center rounded-full bg-crimson-600 text-white">@include('website.partials.icon', ['name' => 'sos'])</span>
                        <h2 class="text-lg font-bold text-crimson-900">Emergency?</h2>
                    </div>
                    <p class="mt-3 text-crimson-900/85">This form is not monitored around the clock. If you are in danger, call <a href="tel:15" class="font-bold underline underline-offset-2">15</a> now. During a trip, use the SOS button in the app.</p>
                </div>
                <div class="card">
                    <h2 class="text-lg font-bold">Support</h2>
                    <dl class="mt-5 space-y-5 text-[15px]">
                        @if (! empty($support['hours']))
                            <div class="flex gap-4"><dt class="text-crimson-600">@include('website.partials.icon', ['name' => 'clock'])<span class="sr-only">Hours</span></dt><dd><span class="block font-semibold">Support hours</span><span class="text-ink-soft">{{ $support['hours'] }}</span></dd></div>
                        @endif
                        @if (! empty($support['email']))
                            <div class="flex gap-4"><dt class="text-crimson-600">@include('website.partials.icon', ['name' => 'mail'])<span class="sr-only">Email</span></dt><dd><span class="block font-semibold">Email</span><a class="break-all text-ink-soft hover:text-crimson-700" href="mailto:{{ $support['email'] }}">{{ $support['email'] }}</a></dd></div>
                        @endif
                        @if (! empty($support['phone']))
                            <div class="flex gap-4"><dt class="text-crimson-600">@include('website.partials.icon', ['name' => 'phone'])<span class="sr-only">Phone</span></dt><dd><span class="block font-semibold">Phone</span><a class="text-ink-soft hover:text-crimson-700" href="tel:{{ preg_replace('/[^0-9+]/', '', $support['phone']) }}">{{ $support['phone'] }}</a></dd></div>
                        @endif
                    </dl>
                </div>
                <div class="card">
                    <h2 class="text-lg font-bold">Looking for a quick answer?</h2>
                    <p class="mt-2 text-ink-soft">Most questions about fares, safety and driving are answered in our FAQ.</p>
                    <a href="{{ route('website.faq') }}" class="mt-4 inline-flex items-center gap-1.5 font-semibold text-crimson-700 hover:gap-2.5 transition-all">Browse the FAQ @include('website.partials.icon', ['name' => 'arrow-right', 'class' => 'size-4'])</a>
                </div>
            </aside>
        </div>
    </section>
@endsection
