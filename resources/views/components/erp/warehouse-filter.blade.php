<select aria-label="Warehouse" name="warehouse_id" class="rounded-lg border-line">
    <option value="">All warehouses</option>
    @foreach(\App\Models\Warehouse::orderBy('name')->get() as $warehouse)
    <option value="{{ $warehouse->id }}" @selected(request('warehouse_id')==$warehouse->id)>{{ $warehouse->name }}</option>
    @endforeach
</select>
