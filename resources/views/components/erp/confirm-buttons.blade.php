@props(['module', 'kind'])
@php
$permission= $module==='finished-goods' ? 'finished-goods.confirm' : ($module==='production' ? 'production.confirm' : ($module==='purchasing' ? App\Modules\Purchasing\Services\PurchasingService::PERMISSIONS[$kind] : App\Modules\Processing\Services\ProcessingService::PERMISSIONS[$kind]));
$canConfirm=auth()->user()->hasPermissionTo($permission);
$label=App\Modules\Core\Support\DocumentPresentation::label($module,$kind);
@endphp
<div class="flex flex-wrap items-center gap-3">
@if($canConfirm)<button type="submit" name="intent" value="confirm" class="rounded-lg bg-brand px-5 py-3 text-sm font-semibold text-white">{{ $label }}</button>@endif
<button type="submit" name="intent" value="save" class="rounded-lg border border-line bg-white px-5 py-3 text-sm {{ $canConfirm?'':'font-semibold text-brand' }}">Save for later</button>
</div>
@if(!$canConfirm)<p class="mt-3 text-sm text-slate-500">You can prepare this entry. An authorized user must confirm it before it takes effect.</p>@endif
