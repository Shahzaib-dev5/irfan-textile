<section class="mt-5 rounded-xl border border-line bg-white p-5 text-sm">
<h2 class="mb-3 font-semibold">Fabric / laat details</h2>
<div class="grid gap-2 md:grid-cols-2">
@foreach(['fabric_type'=>'Fabric type','color'=>'Color / shade','design_reference'=>'Design','component_type'=>'Component / laat type','laat_reference'=>'Laat reference','component_count'=>'Actual components recorded at receipt','instructions'=>'Instructions'] as $field=>$label)
@if(!empty($fabricFlow->details[$field]))<p><span class="text-slate-500">{{ $label }}:</span> {{ $fabricFlow->details[$field] }}</p>@endif
@endforeach
</div>
@if($fabricFlow->onward_document_id)<p class="mt-3">Direct vendor handover: <a class="text-brand underline" href="{{ route('processing.show',$fabricFlow->onward_document_id) }}">{{ $fabricFlow->onwardDocument->number }}</a>. No warehouse receipt was recorded.</p>@endif
<p class="mt-3 text-xs text-slate-500">Component counts describe this receipt. Physical custody is tracked in the fabric stock unit; suit billing is recorded separately.</p>
</section>
