@props(['name' => 'warehouse_location_id', 'bindName' => null, 'warehouse' => false, 'model' => null, 'value' => null, 'locations' => null, 'required' => true])
@php
    $stores = \App\Models\Warehouse::with(['locations' => fn($q) => $q->where('location_type','internal')->where('is_active',true)->orderBy('id')])->where('is_active',true)->orderBy('name')->get();
    $choices = $stores->map(function($store) use ($warehouse, $locations) {
        $places = $locations === null ? $store->locations : $locations->where('warehouse_id',$store->id)->where('location_type','internal');
        $place = $places->firstWhere('code','MAIN') ?? $places->first();
        return $warehouse || $place ? ['id'=>$warehouse ? $store->id : $place->id, 'name'=>$store->name] : null;
    })->filter()->values();
    $selected = old($name, $value ?? ($choices->count() === 1 ? $choices->first()['id'] : ''));
@endphp
<label class="block text-sm">Warehouse
    <select @required($required) @if($bindName) :name="{{ $bindName }}" @else name="{{ $name }}" @endif
        @if($model) x-model="{{ $model }}" @if($choices->count()===1) x-init="if (!{{ $model }}) {{ $model }} = '{{ $choices->first()['id'] }}'" @endif @endif
        {{ $attributes }} class="mt-1 block w-full rounded-lg border border-line p-2">
        <option value="">{{ $required ? 'Choose warehouse' : 'All warehouses' }}</option>
        @foreach($choices as $choice)<option value="{{ $choice['id'] }}" @selected((string)$selected===(string)$choice['id'])>{{ $choice['name'] }}</option>@endforeach
    </select>
</label>
