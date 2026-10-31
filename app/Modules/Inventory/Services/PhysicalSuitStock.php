<?php

namespace App\Modules\Inventory\Services;

use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\ManufacturingBatch;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\StockPosition;
use App\Models\WarehouseLocation;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Production\Services\BomService;
use App\Modules\Production\Services\ProductionService;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class PhysicalSuitStock
{
    public function confirm(int $actor, array $input): Document
    {
        return DB::transaction(function () use ($actor, $input) {
            InventoryGuard::company();
            InventoryGuard::actor($actor, 'inventory.manage');
            $d = validator($input, self::rules())->validate();
            InventoryGuard::actor($actor, $d['source'] === 'opening' ? 'inventory.post' : 'production.confirm');
            if ($d['source'] === 'made') {
                InventoryGuard::actor($actor, 'production.manage');
            }
            $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
            $key = fn (string $suffix) => Uuid::uuid5(Uuid::NAMESPACE_URL, 'physical-suits:'.$d['request_key'].':'.$suffix)->toString();
            if ($existing = Document::where('idempotency_key', $key('stock'))->first()) {
                $audit = AuditEvent::where('document_id', $existing->id)->where('action', 'physical_suits_confirmed')->first();
                if (($audit?->after_json['input_hash'] ?? null) !== $hash) {
                    InventoryGuard::fail('This request was already used for different stock details.');
                }

                return $existing;
            }
            $variant = ProductVariant::with('item.baseUnit', 'design', 'color')->findOrFail($d['product_variant_id']);
            if (! $variant->is_active || ! $variant->design->is_active || ! $variant->color->is_active || ! $variant->item->is_active || ! $variant->item->is_stocked || $variant->item->item_type !== 'finished' || $variant->item->baseUnit->code !== 'SUIT') {
                InventoryGuard::fail('Choose an active finished SUIT variant.');
            }
            if (StockLot::where('lot_number', $d['batch_number'])->exists()) {
                InventoryGuard::fail('This batch number already exists. Use a new batch or record a count correction.');
            }
            $location = WarehouseLocation::with('warehouse')->findOrFail($d['warehouse_location_id']);
            if (! $location->is_active || ! $location->warehouse->is_active || ! in_array($location->location_type, ['internal', 'quarantine'], true)) {
                InventoryGuard::fail('Receive ready suits into active company internal or quarantine storage.');
            }
            if ($d['source'] === 'opening') {
                $inventory = app(InventoryService::class);
                $doc = $inventory->save($actor, ['kind' => 'adjustment', 'document_date' => $d['document_date'], 'idempotency_key' => $key('stock'), 'adjustment_reason' => 'opening', 'reference' => $d['reference'], 'explanation' => $d['remarks'], 'lines' => [[
                    'item_id' => $variant->item_id, 'warehouse_location_id' => $d['warehouse_location_id'], 'lot_number' => $d['batch_number'], 'external_lot_reference' => $d['volume_reference'] ?? null, 'quality_status' => $d['quality_status'], 'quantity_delta' => $d['quantity'], 'positive_unit_cost_pkr' => $d['unit_cost'],
                ]]]);
                $doc = $inventory->post($actor, $doc->id, $key('post'), $doc->lock_version);
            } else {
                InventoryGuard::actor($actor, 'production.manage');
                $lines = [];
                foreach ($d['materials'] as $line) {
                    $p = StockPosition::with('lot.item')->findOrFail($line['stock_position_id']);
                    $lines[] = ['component_item_id' => $p->lot->item_id, 'component_name' => 'Actual material used', 'net_quantity' => $line['quantity'], 'planned_wastage_fraction' => '0'];
                }
                // A one-off actual recipe uses the complete output as its basis: no rounded per-suit conversion.
                $bom = app(BomService::class)->create($actor, ['product_variant_id' => $variant->id, 'output_quantity' => $d['quantity'], 'lines' => $lines]);
                $production = app(ProductionService::class);
                $batch = $production->confirm($actor, ['kind' => 'batch', 'document_date' => $d['document_date'], 'idempotency_key' => $key('batch'), 'bill_of_material_id' => $bom->id, 'planned_output_quantity' => $d['quantity'], 'instructions' => $d['remarks']]);
                $plans = ManufacturingBatch::findOrFail($batch->id)->lines()->orderBy('line_no')->get();
                $materials = [];
                foreach (array_values($d['materials']) as $i => $line) {
                    $materials[] = ['manufacturing_batch_material_id' => $plans[$i]->id, 'stock_position_id' => $line['stock_position_id'], 'quantity' => $line['quantity']];
                }
                $doc = $production->confirm($actor, ['kind' => 'report', 'document_date' => $d['document_date'], 'idempotency_key' => $key('stock'), 'manufacturing_batch_document_id' => $batch->id, 'is_final' => true, 'report_note' => $d['remarks'], 'materials' => $materials, 'outputs' => [[
                    'item_id' => $variant->item_id, 'warehouse_location_id' => $d['warehouse_location_id'], 'lot_number' => $d['batch_number'], 'quality_status' => $d['quality_status'], 'quantity' => $d['quantity'], 'output_kind' => 'finished', 'cost_allocation_fraction' => '1',
                ]]]);
                StockLot::where('origin_document_id', $doc->id)->update(['external_lot_reference' => $d['volume_reference'] ?? null]);
            }
            app(AuditLogger::class)->record('documents', $doc->id, 'physical_suits_confirmed', null, ['input_hash' => $hash, 'input' => $d], $actor, $doc->id);

            return $doc;
        }, 3);
    }

    public static function rules(): array
    {
        return [
            'request_key' => 'required|uuid', 'source' => 'required|in:opening,made', 'document_date' => 'required|date_format:Y-m-d',
            'product_variant_id' => 'required|integer|exists:product_variants,id', 'quantity' => 'required|integer|min:1|max:999999999',
            'warehouse_location_id' => 'required|integer|exists:warehouse_locations,id', 'batch_number' => 'required|string|max:50',
            'volume_reference' => 'nullable|string|max:100', 'quality_status' => 'required|in:pending,released,held,rejected',
            'remarks' => 'required|string|min:5|max:1000', 'reference' => 'required_if:source,opening|nullable|string|max:120',
            'unit_cost' => 'required_if:source,opening|nullable|numeric|gt:0',
            'materials' => 'required_if:source,made|array|max:100', 'materials.*.stock_position_id' => 'required|integer|distinct|exists:stock_positions,id',
            'materials.*.quantity' => 'required|numeric|gt:0',
        ];
    }
}
