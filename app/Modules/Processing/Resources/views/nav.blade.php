@php
$workspaceLabel=$page['title']??match(true){
request()->routeIs('processing.overview')=>'Start here',
request()->routeIs('processing.services.*')=>'Service types',
request()->routeIs('processing.routes*')=>'Processing routes',
request()->routeIs('processing.custody')=>'Stock with vendors',
request()->routeIs('processing.payables')=>'Payable sources',
request()->routeIs('processing.reports')=>'Reports',
default=>'All documents',
};
@endphp
<nav class="mb-6 flex flex-wrap items-center gap-2 text-sm print:hidden" aria-label="Processing workspace"><a href="{{ route('processing.overview') }}" class="font-medium text-brand">Processing</a><span class="text-slate-300">/</span><span class="text-slate-500">{{ $workspaceLabel }}</span><a href="{{ route('processing.overview') }}" class="ml-auto rounded-lg border border-line bg-white px-3 py-2 text-xs">Workflow guide</a></nav>
