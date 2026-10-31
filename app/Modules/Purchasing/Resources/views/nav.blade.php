@php
$purchasingLabel=$page['title']??match(true){request()->routeIs('purchasing.overview')=>'Start here',request()->routeIs('purchasing.history')=>'Prices & history',request()->routeIs('purchasing.payables')=>'Payable sources',default=>'All documents'};
@endphp
<nav class="mb-6 flex flex-wrap items-center gap-2 text-sm print:hidden" aria-label="Purchasing workspace"><a href="{{ route('purchasing.overview') }}" class="font-medium text-brand">Purchasing</a><span class="text-slate-300">/</span><span class="text-slate-500">{{ $purchasingLabel }}</span><a href="{{ route('purchasing.overview') }}" class="ml-auto rounded-lg border border-line bg-white px-3 py-2 text-xs">Workflow guide</a></nav>
