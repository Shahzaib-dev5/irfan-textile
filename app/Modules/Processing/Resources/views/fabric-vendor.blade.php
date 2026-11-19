@php($prefix=$next?'next_':'')
<div class="grid gap-4 md:grid-cols-2" x-data="{
    vendor: @js((string)old($prefix.'vendor_party_role_id',$values[$prefix.'vendor_party_role_id']??'')),
    capability: @js((string)old($prefix.'vendor_service_id',$values[$prefix.'vendor_service_id']??'')),
    rate: @js((string)old($prefix.'agreed_unit_rate_pkr',$values[$prefix.'agreed_unit_rate_pkr']??'')),
    location: @js((string)old($prefix.'contractor_location_id',$values[$prefix.'contractor_location_id']??'')),
    choices: @js($capabilities->map(fn($c)=>['id'=>(string)$c->id,'vendor'=>(string)$c->vendor_party_role_id,'unit'=>$c->processingService->billingUnit->code])->values()),
    unit(){return this.choices.find(c=>c.id===this.capability)?.unit??''}
}">
<label class="text-sm">{{ $next?'Next vendor':'Vendor' }}<select required name="{{ $prefix }}vendor_party_role_id" x-model="vendor" @change="capability='';rate='';location=''" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"><option value="">Select vendor</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->party->legal_name }}</option>@endforeach</select></label>
<label class="text-sm">Service<select required name="{{ $prefix }}vendor_service_id" x-model="capability" @change="rate=''" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"><option value="">Select service</option>@foreach($capabilities as $c)<option value="{{ $c->id }}" x-show="vendor==='{{ $c->vendor_party_role_id }}'" :disabled="vendor!=='{{ $c->vendor_party_role_id }}'">{{ $c->processingService->name }} / {{ $c->processingService->billingUnit->code }}</option>@endforeach</select></label>
<label class="text-sm">Agreed PKR rate (optional) / <span x-text="unit()"></span><input type="number" step="0.00000001" min="0" name="{{ $prefix }}agreed_unit_rate_pkr" x-model="rate" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"><span class="text-xs text-slate-500">Leave blank if undecided. Enter the actual rate and billing unit on Fabric In.</span></label>
<p class="text-xs text-slate-500">Vendor custody is tracked automatically.</p>
<details class="md:col-span-2"><summary class="cursor-pointer text-sm text-slate-500">Agreement notes and loss allowance (optional)</summary><div class="mt-3 grid gap-4 md:grid-cols-2"><label class="text-sm">Rate agreement notes<input name="{{ $prefix }}override_reason" value="{{ old($prefix.'override_reason',$values[$prefix.'override_reason']??'') }}" maxlength="500" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"></label>
<label class="text-sm">Allowed normal loss %<input type="number" min="0" max="99.999999" step="0.000001" name="{{ $prefix }}normal_loss_percent" value="{{ old($prefix.'normal_loss_percent',$values[$prefix.'normal_loss_percent']??'0') }}" class="mt-1 block w-full rounded-lg border border-line px-3 py-2"></label>
</div></details>
</div>
