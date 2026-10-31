@extends('module.core::layouts.app')
@section('title', 'Page not found')
@section('content')
    <div class="mx-auto max-w-lg py-16 text-center"><p class="text-sm font-semibold text-brand">404 · Page not found</p><h1 class="mt-3 text-3xl font-semibold">We could not find that page.</h1><p class="mt-3 text-sm leading-6 text-slate-500">The address may have changed, or the page may not be available yet.</p><a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="mt-6 inline-flex rounded-lg bg-brand px-4 py-2.5 text-sm font-medium text-white">Return to workspace</a></div>
@endsection
