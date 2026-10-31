@extends('module.core::layouts.app')
@section('title',$kind==='out'?'Fabric Out':'Fabric In')
@section('content')
@php($page=['title'=>$kind==='out'?'Fabric Out':'Fabric In'])
@include('module.processing::nav')
<h1 class="text-2xl font-semibold">{{ $page['title'] }}</h1>
<p class="mb-6 mt-2 text-sm text-slate-600">{{ $kind==='out'?'Choose the vendor, service and actual fabric. The expected processed fabric is prepared automatically.':'Record the processing result, then receive it at your warehouse or hand it directly to another vendor.' }}</p>
@if($errors->any())<div role="alert" class="mb-5 rounded-xl border border-red-300 bg-red-50 p-4 text-sm">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($kind==='in' && !$flow)
<form method="GET" action="{{ route('processing.fabric.in') }}" class="mb-5 rounded-xl border border-line bg-white p-5"><label class="text-sm">Outstanding Fabric Out / vendor / service<select required name="line" class="my-2 block w-full rounded-lg border border-line px-3 py-2"><option value="">Choose fabric to receive</option>@foreach($dispatchLines as $line)<option value="{{ $line->id }}" @selected($selectedLine?->id===$line->id)>{{ $line->dispatch->vendor->role->party->legal_name }} / {{ $line->dispatch->document->number }} line {{ $line->line_no }} / {{ $line->step->service->name }} / {{ $processing->outstanding($line) }} {{ $line->step->inputItem->baseUnit->code }} outstanding</option>@endforeach</select></label><button class="rounded-lg border border-line px-4 py-2 text-sm">Load fabric</button></form>
@endif
@if($kind==='out' || $selectedLine)
<form method="POST" action="{{ route('processing.fabric.store') }}" class="space-y-5" x-data="{date:@js(old('document_date',$values['document_date']??App\Modules\Inventory\Services\InventoryGuard::businessDate())),destination:@js(old('destination',$values['destination']??'warehouse'))}">
@csrf
<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="request_key" value="{{ old('request_key',$values['request_key']??(string)Illuminate\Support\Str::uuid()) }}">
<section class="rounded-xl border border-line bg-white p-5"><label class="text-sm">Date<input type="date" required name="document_date" x-model="date" class="mt-1 block rounded-lg border border-line px-3 py-2"></label></section>
@if($kind==='out')
<section class="rounded-xl border border-line bg-white p-5">@include('module.processing::fabric-vendor',['next'=>false])</section>
<section class="grid gap-4 rounded-xl border border-line bg-white p-5 md:grid-cols-2">
<label class="text-sm md:col-span-2">Available fabric / lot / roll<select required name="source_stock_position_id" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"><option value="">Select actual stock</option>@foreach($stock as $p)<option value="{{ $p->id }}" @selected(old('source_stock_position_id',$values['source_stock_position_id']??'')==$p->id)>{{ $p->lot->item->name }} / {{ $p->lot->lot_number }} / {{ $p->roll?->roll_number??'Unrolled' }} / {{ $p->location->name }} / {{ bcsub($p->balance->on_hand_quantity,$p->balance->reserved_quantity,6) }} {{ $p->lot->item->baseUnit->code }} available{{ $p->roll?' (send full roll)':'' }}</option>@endforeach</select></label>
<label class="text-sm">Quantity in the selected fabric's stock unit<input required type="number" min="0.000001" step="0.000001" name="quantity" value="{{ old('quantity',$values['quantity']??'') }}" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"><span class="text-xs text-slate-500">For meter stock, enter measured meters. Roll counts do not convert automatically.</span></label>
<label class="text-sm">Instructions<input name="instructions" maxlength="500" value="{{ old('instructions',$values['instructions']??'') }}" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"></label>
</section>
@else
<input type="hidden" name="processing_dispatch_line_id" value="{{ $selectedLine->id }}">
<section class="rounded-xl border border-line bg-white p-5 text-sm"><p class="font-semibold">{{ $selectedLine->dispatch->vendor->role->party->legal_name }} / {{ $selectedLine->step->service->name }}</p><p class="mt-2">Expected result: {{ $selectedLine->step->outputItem->name }}</p><p>Outstanding: {{ $processing->outstanding($selectedLine) }} {{ $selectedLine->step->inputItem->baseUnit->code }} · Rate: PKR {{ $selectedLine->step->agreed_unit_rate_pkr }} / {{ $selectedLine->step->billingUnit->code }}</p></section>
<section class="grid gap-4 rounded-xl border border-line bg-white p-5 md:grid-cols-2">
@foreach(['source_quantity'=>'Quantity being closed','output_quantity'=>'Actual returned fabric quantity','normal_loss_quantity'=>'Normal loss','abnormal_loss_quantity'=>'Abnormal loss'] as $field=>$label)<label class="text-sm">{{ $label }} ({{ $selectedLine->step->inputItem->baseUnit->code }})<input required type="number" min="0" step="0.000001" name="{{ $field }}" value="{{ old($field,$values[$field]??(str_contains($field,'loss')?'0':'')) }}" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"></label>@endforeach
<p class="text-xs text-slate-500 md:col-span-2">Quantity closed = returned fabric + losses. The remainder stays with this vendor.</p>
<label class="text-sm">Laat reference<input required maxlength="80" name="laat_reference" value="{{ old('laat_reference',$values['laat_reference']??'') }}" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"></label>
<label class="text-sm">Actual component count (for example 66 dupattas)<input type="number" min="1" step="1" name="component_count" value="{{ old('component_count',$values['component_count']??'') }}" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"></label>
<label class="text-sm">Billable quantity ({{ $selectedLine->step->billingUnit->code }})<input type="number" min="0" step="{{ $selectedLine->step->billingUnit->decimal_places===0?'1':'0.000001' }}" name="accepted_service_quantity" value="{{ old('accepted_service_quantity',$values['accepted_service_quantity']??'') }}" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"><span class="text-xs text-slate-500">Per-suit charges use the agreed inspected suit quantity, separately from component count. Same-unit services use actual returned quantity.</span></label>
<label class="text-sm">Result<select name="disposition" class="mt-1 block w-full rounded-lg border border-line px-3 py-2">@foreach(['accepted'=>'Accepted processing','rejected'=>'Returned unchanged / rejected'] as $v=>$label)<option value="{{ $v }}" @selected(old('disposition',$values['disposition']??'accepted')===$v)>{{ $label }}</option>@endforeach</select></label>
<label class="text-sm">Quality<select name="quality_status" class="mt-1 block w-full rounded-lg border border-line px-3 py-2">@foreach(['pending'=>'Pending inspection','released'=>'Inspected and released','held'=>'Held','rejected'=>'Rejected'] as $v=>$label)<option value="{{ $v }}" @selected(old('quality_status',$values['quality_status']??'pending')===$v)>{{ $label }}</option>@endforeach</select></label>
@if($selectedLine->step->outputItem->track_rolls)<label class="text-sm">New returned roll number<input required name="roll_number" maxlength="80" value="{{ old('roll_number',$values['roll_number']??'') }}" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"></label>@endif
<label class="text-sm md:col-span-2">Inspection / billing evidence<textarea name="remarks" maxlength="1000" class="mt-1 block w-full rounded-lg border border-line px-3 py-2">{{ old('remarks',$values['remarks']??'') }}</textarea></label>
</section>
@endif
<section class="grid gap-4 rounded-xl border border-line bg-white p-5 md:grid-cols-2">
@foreach(['fabric_type'=>'Fabric type (lawn, chiffon, etc.)','color'=>'Color / target shade','component_type'=>'Component / laat type (dupatta, shirt, suit, etc.)','design_reference'=>'Embroidery design / reference'] as $field=>$label)
<label class="text-sm">{{ $label }}<input name="{{ $field }}" maxlength="100" @if($kind==='out' && $field==='fabric_type') required @endif value="{{ old($field,$values[$field]??'') }}" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"></label>
@endforeach
<p class="text-xs text-slate-500 md:col-span-2">Physical stock keeps its measured unit. Laat/component counts describe the inspected batch; they do not create complete suits or convert meters into pieces.</p>
</section>
@if($kind==='in')
<section class="space-y-4 rounded-xl border border-line bg-white p-5"><label class="text-sm">Where did this fabric go?<select name="destination" x-model="destination" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"><option value="warehouse">Our warehouse</option><option value="vendor">Directly to another vendor</option></select></label>
<fieldset x-show="destination==='warehouse'" :disabled="destination!=='warehouse'"><label class="text-sm">Receiving location<select required name="receiving_location_id" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"><option value="">Select warehouse location</option>@foreach($locations->whereIn('location_type',['internal','quarantine']) as $location)<option value="{{ $location->id }}" @selected(old('receiving_location_id',$values['receiving_location_id']??'')==$location->id)>{{ $location->warehouse->name }} / {{ $location->name }}</option>@endforeach</select></label></fieldset>
<fieldset x-cloak x-show="destination==='vendor'" :disabled="destination!=='vendor'" class="space-y-4"><p class="text-sm text-slate-600">Confirm only after the next vendor has received the fabric. Accepted, inspected and released output moves directly between vendors; no warehouse receipt is created.</p>@include('module.processing::fabric-vendor',['next'=>true])</fieldset>
</section>
@endif
@if(!empty($values['manufacturing_batch_document_id']))<input type="hidden" name="manufacturing_batch_document_id" value="{{ $values['manufacturing_batch_document_id'] }}">@endif
@if($flow)<p class="text-sm text-slate-500">Saved entry: confirm these saved details. To change them, start a new Fabric {{ ucfirst($kind) }} entry.</p>@endif
<div class="flex gap-3"><button name="intent" value="confirm" class="rounded-lg bg-brand px-6 py-3 text-sm text-white">Confirm Fabric {{ ucfirst($kind) }}</button><button name="intent" value="save" class="rounded-lg border border-line bg-white px-5 py-3 text-sm">Save for later</button></div>
</form>
@endif
@endsection
