@extends('module.core::layouts.app')

@section('title', 'Sign in')
@section('breadcrumb', 'Identity / Sign in')

@section('content')
    <div class="mx-auto grid min-h-[65vh] max-w-5xl items-center gap-10 lg:grid-cols-[1fr_420px]">
        <div class="hidden lg:block"><span class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-white px-3 py-1.5 text-xs font-medium text-brand"><span class="size-2 rounded-full bg-emerald-500"></span>Textile operations</span><h1 class="mt-6 max-w-xl text-4xl font-semibold leading-tight tracking-tight">One workspace for every stage of your business.</h1><p class="mt-4 max-w-lg text-base leading-7 text-slate-500">Manage procurement, processing, production and wholesale operations with connected records and clear visibility.</p></div>
        <div class="rounded-2xl border border-line bg-white p-6 shadow-sm sm:p-8"><div class="mb-7"><x-erp.brand-mark class="size-11" /><h2 class="mt-5 text-xl font-semibold">Welcome back</h2><p class="mt-1 text-sm text-slate-500">Sign in to your company workspace.</p></div>
            <form method="POST" action="{{ route('login.store') }}" class="space-y-5">@csrf
                <div><label for="email" class="mb-1.5 block text-sm font-medium">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus class="w-full rounded-lg border border-line px-3.5 py-3 text-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-emerald-900/10" placeholder="you@company.com"></div>
                <div><label for="password" class="mb-1.5 block text-sm font-medium">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required class="w-full rounded-lg border border-line px-3.5 py-3 text-sm outline-none transition focus:border-brand focus:ring-4 focus:ring-emerald-900/10" placeholder="Enter your password"></div>
                <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-brand focus:ring-brand">Keep me signed in</label>
                <button type="submit" class="w-full rounded-lg bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark focus:outline-none focus:ring-4 focus:ring-emerald-900/20">Sign in</button>
            </form><p class="mt-6 border-t border-line pt-5 text-center text-xs leading-5 text-slate-400">Contact your system administrator if you need access.</p>
        </div>
    </div>
@endsection
