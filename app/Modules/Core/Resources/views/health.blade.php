@extends('module.core::layouts.app')

@section('title', 'System health')
@section('breadcrumb', 'System / Health')

@section('content')
    <div class="mx-auto max-w-3xl"><p class="text-sm font-medium text-brand">System status</p><h1 class="mt-1 text-3xl font-semibold tracking-tight">Application health</h1><p class="mt-2 text-sm text-slate-500">A successful response confirms the web application and configured database connection are available.</p>
        <div class="mt-8 rounded-2xl border border-line bg-white p-6"><div class="flex items-center justify-between border-b border-line pb-4"><span class="text-sm font-medium">Application</span><span class="inline-flex items-center gap-2 text-sm font-medium text-emerald-700"><span class="size-2 rounded-full bg-emerald-500"></span>Healthy</span></div><div class="flex items-center justify-between pt-4"><span class="text-sm font-medium">Database</span><span class="inline-flex items-center gap-2 text-sm font-medium text-emerald-700"><span class="size-2 rounded-full bg-emerald-500"></span>Connected</span></div></div>
        <p class="mt-4 text-xs text-slate-400">Checked {{ now()->timezone('Asia/Karachi')->format('d M Y, H:i:s T') }}</p>
    </div>
@endsection
