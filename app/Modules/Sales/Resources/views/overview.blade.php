@extends('module.core::layouts.app')
@section('title','Wholesale sales - Start here')
@section('content')
@include('module.sales::nav')
<h1 class="text-3xl font-semibold">Wholesale sales</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-500">Choose your customer, confirm an order, dispatch actual finished stock, then bill that delivery. Confirm performs the transaction once. Save for later keeps unfinished work.</p>
<section class="mt-6 rounded-xl bg-emerald-50 p-5 text-sm"><h2 class="font-semibold">Before you start</h2><p class="mt-2">The administrator must configure customer terms and credit limit, an open financial period and the sales posting accounts. Zero credit limit means no credit. Finished SUIT variants or PACK items must be received and released in internal warehouse stock.</p><p class="mt-2">Your Wholesale account works inside Sales. It does not grant access to Purchasing, Processing, production costs or company administration.</p></section>
<div class="mt-6 grid gap-4 md:grid-cols-2">@foreach([
['order','1. Confirm the customer order','Choose actual finished items, quantities, unit prices, discounts and delivery address. Credit includes approved unfulfilled orders. Orders can remain partly unfulfilled as backorders.'],
['dispatch','2. Reserve and dispatch available stock','Optionally reserve lots on the order. Select the source warehouse and actual released lot for each line. Partial deliveries are allowed; unavailable or quarantined stock cannot leave.'],
['invoice','3. Invoice the confirmed dispatch','Select the delivered quantities. The invoice matches the frozen dispatch price and creates customer receivables without another stock issue or revenue entry. Preview or download A4 PDF.'],
['return','4. Receive customer returns','Select the original dispatch and actual returned quantity. Use internal storage for saleable goods and quarantine for damaged/uninspected goods. This restores stock, not the customer balance.'],
['credit','5. Issue a commercial credit','Link the customer invoice and return, or document an authorized price adjustment. Credit reduces the receivable. It does not receive the returned stock again.']
] as [$key,$title,$help])<section class="rounded-xl border border-line bg-white p-6"><h2 class="font-semibold">{{ $title }}</h2><p class="mt-3 text-sm leading-6 text-slate-500">{{ $help }}</p><a href="{{ route('sales.index',['kind'=>$key]) }}" class="mt-4 inline-block text-sm font-medium text-brand">Open screen &rarr;</a></section>@endforeach</div>
<p class="mt-6 text-sm text-slate-500">Close an order's remaining balance to cancel its backorder and release reservations. Confirmed transactions retain their history; use physical returns and credit notes for corrections. An authorized Finance user records payments in Accounting & finance. Tax calculations are not enabled.</p>
@endsection
