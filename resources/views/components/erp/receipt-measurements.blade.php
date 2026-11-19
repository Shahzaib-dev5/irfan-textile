@props(['documentId'])
@php
$measurements=Illuminate\Support\Facades\DB::table('receipt_measurements as m')->join('goods_receipt_lines as l','l.id','=','m.goods_receipt_line_id')->join('units as u','u.id','=','m.rate_unit_id')->where('l.document_id',$documentId)->select('m.*','l.line_no','u.code')->get();
@endphp
@if($measurements->isNotEmpty())
<section class="mb-6 rounded-xl border border-line bg-white p-5"><h2 class="font-semibold">Original received measurements</h2><p class="mt-1 text-xs text-slate-500">Additional quantities describe the original delivery, not extra stock or the remaining balance.</p>
@foreach($measurements as $measurement)<div class="mt-3 border-t border-line pt-3 text-sm"><strong>Line {{ $measurement->line_no }}</strong><p>Quoted rate: PKR {{ $measurement->quoted_rate }} per {{ $measurement->code }} · Rate quantity: {{ $measurement->rate_quantity }}</p><p>@if($measurement->thaan_count){{ $measurement->thaan_count }} thaan · @endif @if($measurement->set_count){{ $measurement->set_count }} sets · @endif @if($measurement->measured_meters){{ $measurement->measured_meters }} M @endif</p></div>@endforeach
</section>
@endif
