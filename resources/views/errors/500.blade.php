@extends('module.core::layouts.app')
@section('title', 'Something went wrong')
@section('content')
    <div class="mx-auto max-w-lg py-16 text-center"><p class="text-sm font-semibold text-brand">500 · Application error</p><h1 class="mt-3 text-3xl font-semibold">Something went wrong.</h1><p class="mt-3 text-sm leading-6 text-slate-500">The request could not be completed. Try again later or contact your system administrator.</p><a href="{{ auth()->check() ? route('dashboard') : route('login') }}" class="mt-6 inline-flex rounded-lg bg-brand px-4 py-2.5 text-sm font-medium text-white">Return to workspace</a></div>
@endsection
