@extends('module.core::layouts.app')
@section('title', 'Reset password')
@section('content')
<div class="mx-auto mt-10 max-w-lg rounded-2xl border border-line bg-white p-7"><h1 class="text-2xl font-semibold">Reset your password</h1><p class="mt-2 text-sm text-slate-500">Enter your account email. If an active account matches, we will send a reset link.</p><form class="mt-6 space-y-4" method="POST" action="{{ route('password.email') }}">@csrf<label class="block text-sm font-medium">Email address<input class="mt-1 w-full rounded-lg border-line text-sm" name="email" type="email" required autocomplete="email" value="{{ old('email') }}"></label>@error('email')<p class="text-sm text-red-600">{{ $message }}</p>@enderror<button class="w-full rounded-lg bg-brand px-4 py-3 text-sm font-semibold text-white">Send reset link</button></form><a class="mt-5 block text-center text-sm text-brand" href="{{ route('login') }}">Back to sign in</a></div>
@endsection
