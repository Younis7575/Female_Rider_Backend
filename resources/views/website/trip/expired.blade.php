@extends('website.layouts.app')

@section('title', 'Trip link expired')
@section('description', 'This FemaleRider trip link is no longer active.')
@section('robots', 'noindex, nofollow, noarchive')
@section('minimal', '1')

@section('content')
    <section class="relative isolate flex min-h-[calc(100dvh-72px)] items-center overflow-hidden bg-brand-soft">
        <div class="container-site py-20">
            <div class="mx-auto max-w-lg text-center">
                <span class="mx-auto flex size-20 items-center justify-center rounded-3xl bg-white text-crimson-600 shadow-lift">@include('website.partials.icon', ['name' => 'clock', 'class' => 'size-10'])</span>
                <h1 class="mt-8 text-3xl font-extrabold sm:text-4xl">This trip link has expired</h1>
                <p class="mt-4 text-lg leading-relaxed text-ink-soft">The trip may have ended, or the rider stopped sharing it. For privacy, live trip links only work while a trip is in progress.</p>
                <p class="mt-3 text-ink-soft">If you’re expecting an update, please contact the person who shared it with you.</p>
                <div class="mt-8 rounded-2xl border border-crimson-200 bg-white p-4 text-sm text-crimson-900">
                    If you believe someone is in danger, call <a href="tel:15" class="font-bold underline underline-offset-2">15</a> immediately.
                </div>
                <a href="{{ route('website.home') }}" class="btn-primary mt-8">Go to FemaleRider</a>
            </div>
        </div>
    </section>
@endsection
