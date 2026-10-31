@extends('module.core::layouts.app')
@section('title',$page['new'])
@section('content')
@include('module.processing::nav')
@php
$productionBatchId=old('manufacturing_batch_document_id',request('batch'));
@endphp
@if($productionBatchId)<p class="mb-4 rounded-lg bg-emerald-50 p-4 text-sm">Production batch #{{ $productionBatchId }}: this job will be traced to its material requirements.</p>@endif
<h1 class="mb-2 text-2xl font-semibold">{{ $page['new'] }}</h1>
<p class="mb-6 text-sm text-slate-500">{{ $page['help'] }}</p>
<section class="mb-5 rounded-xl border border-line bg-white p-5">
<h2 class="mb-3 font-semibold">1. Choose the source</h2>
<form method="GET" action="{{ route(App\Modules\Processing\Services\ProcessingWorkspace::route($kind,'create')) }}" class="flex flex-wrap items-end gap-3">
@if($kind==='job')
<input type="hidden" name="batch" value="{{ $productionBatchId }}"><label class="flex-1 text-sm">Published processing route<select name="route" required class="mt-1 block w-full rounded-lg border border-line p-2"><option value="">Choose a route</option>@foreach($routes as $r)<option value="{{ $r->id }}" @selected($selectedRoute?->id===$r->id)>{{ $r->route_code }} v{{ $r->version_number }} / {{ $r->name }}</option>@endforeach</select></label>
@elseif($kind==='dispatch')
<label class="flex-1 text-sm">Approved job<select name="job" required class="mt-1 block w-full rounded-lg border border-line p-2"><option value="">Choose a job</option>@foreach($jobs as $j)<option value="{{ $j->document_id }}" @selected($selectedJob?->document_id===$j->document_id)>{{ $j->document->number }} / {{ $j->planned_input_quantity }} {{ $j->lines->first()->inputItem->baseUnit->code }}</option>@endforeach</select></label>
@elseif($kind==='receipt')
<label class="flex-1 text-sm">Original posted dispatch<select name="dispatch" required class="mt-1 block w-full rounded-lg border border-line p-2"><option value="">Choose dispatch challan</option>@foreach($dispatches as $d)<option value="{{ $d->document_id }}" @selected($selectedDispatch?->document_id===$d->document_id)>{{ $d->document->number }} / {{ $d->job->document->number }} / {{ $d->vendor->role->party->legal_name }}</option>@endforeach</select></label>
@else
<label class="flex-1 text-sm">Accepted processing receipt<select name="receipt" required class="mt-1 block w-full rounded-lg border border-line p-2"><option value="">Choose a receipt</option>@foreach($receipts as $r)<option value="{{ $r->document_id }}" @selected($selectedReceipt?->document_id===$r->document_id)>{{ $r->document->number }} / {{ $r->vendor->role->party->legal_name }}</option>@endforeach</select></label>
@endif
<button class="rounded-lg bg-brand px-4 py-2 text-sm text-white">Load {{ $kind==='dispatch'?'job stages':'details' }}</button>
</form>
@if($kind==='dispatch' && $selectedJob)
<form method="GET" action="{{ route('processing.dispatches.create') }}" class="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4"><input type="hidden" name="job" value="{{ $selectedJob->document_id }}"><label class="flex-1 text-sm">Stage to dispatch<select name="step" required class="mt-1 block w-full rounded-lg border border-line p-2"><option value="">Choose assigned stage</option>@foreach($selectedJob->lines as $s)<option value="{{ $s->id }}" @selected($selectedStep?->id===$s->id)>Stage {{ $s->line_no }} / {{ $s->service->name }} / {{ $s->inputItem->sku }} to {{ $s->outputItem->sku }} / {{ $s->vendorService->vendor->role->party->legal_name }}</option>@endforeach</select></label><button class="rounded-lg border border-line px-4 py-2 text-sm">Load matching stock</button></form>
@endif
<p class="mt-3 text-xs text-slate-500">Choose a source and load its details before filling quantities below.</p>
</section>
@php
$ready=match($kind){'job'=>(bool)$selectedRoute,'dispatch'=>(bool)$selectedStep,'receipt'=>(bool)$selectedDispatch,'bill'=>(bool)$selectedReceipt};
$choices=match($kind){'dispatch'=>$stock,'receipt'=>$dispatchLines,'bill'=>$billLines,default=>[]};
$hasLocations=$kind==='dispatch'?$contractorLocations->isNotEmpty():($kind==='receipt'?$locations->whereIn('location_type',['internal','quarantine'])->isNotEmpty():true);
$canFill=$ready && ($kind==='job' || (count($choices)>0 && $hasLocations));
$config=['kind'=>$kind,'choices'=>$choices,'defaults'=>$initialLines[0]??[],'lines'=>array_values(old('lines',$initialLines))];
@endphp
@if(!$ready)
<div class="rounded-xl border border-line bg-white p-6 text-sm"><h2 class="mb-2 font-semibold">Start with the source above</h2><p>{{ match($kind){'job'=>'Publish a processing route before creating a job.','dispatch'=>'Approve a job, then choose its stage to find matching released fabric.','receipt'=>'Post a dispatch challan before receiving fabric from the vendor.','bill'=>'Post an accepted processing receipt before matching a contractor invoice.'} }}</p><a class="mt-3 inline-block text-brand" href="{{ route('processing.overview') }}">Open setup checklist and workflow guide</a></div>
@elseif(!$canFill)
<div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm"><h2 class="mb-2 font-semibold">{{ !$hasLocations?'Storage setup is needed':($kind==='dispatch'?'No eligible fabric for this stage':($kind==='receipt'?'This dispatch has no outstanding fabric':'This receipt has no unbilled charges')) }}</h2><p>{{ !$hasLocations?'Create an active receiving location, or contractor location linked to the assigned vendor, under Warehouses.':($kind==='dispatch'?'Check the stage input item, released lot quality, internal warehouse location, reservations and remaining job quantity. Later stages require fabric returned by the previous stage of this job. Tracked rolls must be available in full.':($kind==='receipt'?'All issued quantities have been received. Open another dispatch to record a new receipt.':'Rejected, zero-charge and fully billed services cannot be billed here. Choose another accepted receipt.')) }}</p><a class="mt-3 inline-block text-brand" href="{{ route('processing.overview') }}">Open setup checklist</a></div>
@else
<form method="POST" action="{{ route(App\Modules\Processing\Services\ProcessingWorkspace::route($kind,'store')) }}" class="space-y-5" @if($kind!=='job') x-data="processingDocument(@js($config))" @endif>
@csrf<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',(string)Illuminate\Support\Str::uuid()) }}">
<div class="grid gap-5 rounded-xl border border-line bg-white p-6 md:grid-cols-2"><h2 class="font-semibold md:col-span-2">2. Fill the document details</h2><label class="text-sm">Document date<input required type="date" name="document_date" value="{{ old('document_date',App\Modules\Inventory\Services\InventoryGuard::businessDate()) }}" class="mt-1 block w-full rounded-lg border border-line p-2"><span class="mt-1 block text-xs text-slate-500">An open financial year and accounting period must cover this date.</span></label>
@if($kind==='job')
<input type="hidden" name="manufacturing_batch_document_id" value="{{ $productionBatchId }}"><input type="hidden" name="processing_route_version_id" value="{{ $selectedRoute->id }}"><label class="text-sm">Planned input quantity ({{ $selectedRoute->steps->first()->inputItem->baseUnit->code }})<input required name="planned_input_quantity" inputmode="decimal" value="{{ old('planned_input_quantity') }}" class="mt-1 block w-full rounded-lg border border-line p-2"></label><label class="text-sm">Required by<input type="date" name="required_by" value="{{ old('required_by') }}" class="mt-1 block w-full rounded-lg border border-line p-2"></label><label class="text-sm">Job instructions<textarea name="instructions" class="mt-1 block w-full rounded-lg border border-line p-2">{{ old('instructions') }}</textarea></label>
@else
@if($selectedJob && $kind!=='bill')<input type="hidden" name="processing_job_document_id" value="{{ $selectedJob->document_id }}">@endif
<input type="hidden" name="vendor_party_role_id" value="{{ $vendor->id }}"><div class="text-sm"><span class="block text-xs text-slate-500">ASSIGNED VENDOR</span><p class="mt-2 font-medium">{{ $vendor->party->legal_name }}</p><p class="mt-1 text-xs text-slate-500">Taken from the selected {{ $kind==='dispatch'?'job stage':($kind==='receipt'?'dispatch':'receipt') }}.</p></div>
@if($selectedStep && $kind==='dispatch')<div class="text-sm md:col-span-2">Stage {{ $selectedStep->line_no }} / {{ $selectedStep->service->name }} / Input: <strong>{{ $selectedStep->inputItem->sku }}</strong> / Output: {{ $selectedStep->outputItem->sku }}<p class="mt-2 text-xs text-slate-500">Stage issued: {{ $progress[$selectedStep->id]['issued'] }} / Still with vendor: {{ $progress[$selectedStep->id]['outstanding'] }} {{ $selectedStep->inputItem->baseUnit->code }}.</p></div>@endif
@if($kind==='bill')
@foreach(['supplier_invoice_number'=>'Contractor invoice number','supplier_invoice_date'=>'Contractor invoice date','due_on'=>'Due on'] as $field=>$label)<label class="text-sm">{{ $label }}<input required type="{{ $field==='supplier_invoice_number'?'text':'date' }}" name="{{ $field }}" value="{{ old($field) }}" class="mt-1 block w-full rounded-lg border border-line p-2"></label>@endforeach
@else<label class="text-sm">Contractor challan / reference<input name="contractor_reference" value="{{ old('contractor_reference') }}" class="mt-1 block w-full rounded-lg border border-line p-2"></label>@endif
@if($kind!=='dispatch')<label class="text-sm md:col-span-2">Inspection / service-count evidence<textarea name="remarks" class="mt-1 block w-full rounded-lg border border-line p-2">{{ old('remarks') }}</textarea></label>@endif
@endif</div>
@if($kind==='job')
@foreach($selectedRoute->steps as $i=>$stage)<fieldset class="rounded-xl border border-line bg-white p-6"><legend class="px-2 text-sm font-semibold">Stage {{ $stage->step_number }} · {{ $stage->service->name }}</legend><p class="mb-4 text-sm text-slate-500">{{ $stage->inputItem->sku }} → {{ $stage->outputItem->sku }} · Yield {{ $stage->planned_yield_ratio }} · Normal loss allowance {{ bcmul($stage->permitted_normal_loss_fraction,'100',2) }}%</p><div class="grid gap-4 md:grid-cols-2"><label class="text-sm">Vendor capability<select required name="lines[{{ $i }}][vendor_service_id]" class="mt-1 block w-full rounded-lg border border-line p-2"><option value="">Choose capability</option>@foreach($capabilities->where('processing_service_id',$stage->processing_service_id) as $v)<option value="{{ $v->id }}" @selected(old('lines.'.$i.'.vendor_service_id')==$v->id)>{{ $v->vendor->role->party->legal_name }} · PKR / {{ $v->processingService->billingUnit->code }}</option>@endforeach</select></label><label class="text-sm">Rate effective date<input required type="date" name="lines[{{ $i }}][rate_effective_date]" value="{{ old('lines.'.$i.'.rate_effective_date',App\Modules\Inventory\Services\InventoryGuard::businessDate()) }}" class="mt-1 block w-full rounded-lg border border-line p-2"></label>
@if(auth()->user()->hasPermissionTo('processing.rates.override'))<label class="text-sm">Negotiated PKR rate (optional)<input name="lines[{{ $i }}][agreed_unit_rate_pkr]" value="{{ old('lines.'.$i.'.agreed_unit_rate_pkr') }}" placeholder="Blank uses published contract" class="mt-1 block w-full rounded-lg border border-line p-2"></label><label class="text-sm">Override approval reason<input name="lines[{{ $i }}][override_reason]" value="{{ old('lines.'.$i.'.override_reason') }}" class="mt-1 block w-full rounded-lg border border-line p-2"></label>@endif</div></fieldset>@endforeach
@else
<noscript><p class="rounded-lg bg-amber-50 p-4">Enable JavaScript to enter processing lines and view quantity guidance.</p></noscript>
<div x-cloak class="space-y-4"><template x-for="(line,index) in lines" :key="line.rowId">@include('module.processing::line')</template></div>
<button type="button" @click="add()" class="rounded-lg border border-line bg-white px-4 py-2 text-sm">Add line</button>
@endif
<section class="rounded-xl border border-line bg-white p-5 text-sm"><h2 class="mb-2 font-semibold">3. Check and confirm</h2><p>{{ App\Modules\Core\Support\DocumentPresentation::effect('processing',$kind) }}</p><p class="mt-2 text-slate-500">Check the actual quantities, assigned vendor and references above. Confirm saves and finalizes together. Save for later is optional; a saved processing draft must be cancelled and recreated if its details need correction.</p>
@if(in_array($kind,['receipt','bill']))<p class="mt-2 font-medium" x-text="'Estimated total: PKR '+format(total(),4)"></p><p class="mt-1 text-xs text-slate-500">The final charge uses validated quantities, frozen rates and decimal rounding.</p>@endif
@if($kind==='bill')<p class="mt-2 text-xs text-slate-500">Use a unique contractor invoice number. A cancelled saved entry retains its reference for audit.</p>@endif
</section>
<x-erp.confirm-buttons module="processing" :kind="$kind" /><a href="{{ route(App\Modules\Processing\Services\ProcessingWorkspace::route($kind)) }}" class="inline-block py-3 text-sm text-slate-500">Back to {{ strtolower($page['title']) }}</a></form>
@endif
@endsection
