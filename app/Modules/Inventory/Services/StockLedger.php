<?php

namespace App\Modules\Inventory\Services;

use App\Models\Document;
use App\Models\InventoryCostLink;
use App\Models\InventoryLayerAllocation;
use App\Models\InventoryValueEntry;
use App\Models\ProcessingDispatchLine;
use App\Models\StockBalance;
use App\Models\StockCostLayer;
use App\Models\StockMovement;
use App\Models\StockPosition;
use App\Models\StockReservation;
use App\Modules\Inventory\Support\Decimal as D;
use Illuminate\Support\Facades\DB;

/** Internal posting primitive. Call only under the company mutex and a DB transaction. */
class StockLedger
{
    public function onHand(int $position): string
    {
        return bcadd((string) StockMovement::where('stock_position_id', $position)->sum('quantity_delta'), '0', 6);
    }

    public function reserved(int $position, ?int $exceptDocument = null): string
    {
        return bcadd((string) StockReservation::where('stock_position_id', $position)->when($exceptDocument, fn ($q) => $q->where('document_id', '<>', $exceptDocument))->sum(DB::raw('quantity-fulfilled_quantity-released_quantity')), '0', 6);
    }

    public function available(int $position, ?int $exceptDocument = null): string
    {
        return bcsub($this->onHand($position), $this->reserved($position, $exceptDocument), 6);
    }

    public function checkProjection(int $id): void
    {
        $balance = StockBalance::whereKey($id)->lockForUpdate()->firstOrFail();
        if (bccomp($balance->on_hand_quantity, $this->onHand($id), 6) || bccomp($balance->reserved_quantity, $this->reserved($id), 6)) {
            InventoryGuard::fail('Stock projection differs from the ledger. Reconcile before posting.');
        }
        foreach (StockCostLayer::where('stock_position_id', $id)->lockForUpdate()->get() as $layer) {
            if (bccomp($layer->remaining_quantity, (string) $layer->allocations()->sum('quantity_delta'), 6)) {
                InventoryGuard::fail('FIFO projection differs from allocation history. Reconcile before posting.');
            }
        }
        $layers = (string) StockCostLayer::where('stock_position_id', $id)->sum('remaining_quantity');
        if (bccomp($layers, $this->onHand($id), 6)) {
            InventoryGuard::fail('FIFO quantities differ from stock movements.');
        }
    }

    public function refresh(int $position): void
    {
        $qty = $this->onHand($position);
        $reserved = $this->reserved($position);
        if (bccomp($qty, '0', 6) < 0 || bccomp($reserved, $qty, 6) > 0) {
            InventoryGuard::fail('Posting would create negative or over-reserved stock.');
        }
        StockBalance::whereKey($position)->update(['on_hand_quantity' => $qty, 'reserved_quantity' => $reserved, 'lock_version' => DB::raw('lock_version+1'), 'updated_at' => now()]);
    }

    public function position(int $id, string $date, bool $picking = false): StockPosition
    {
        $position = StockPosition::with(['lot.item.baseUnit', 'location.warehouse', 'roll'])->lockForUpdate()->findOrFail($id);
        $item = $position->lot->item;
        if (! $item->is_stocked || ! $item->is_active || ! $item->baseUnit->is_active || ! $position->location->is_active || ! $position->location->warehouse->is_active) {
            InventoryGuard::fail('Choose active stock items and storage.');
        }
        if ((bool) $item->track_rolls !== (bool) $position->fabric_roll_id || ($position->roll && ($position->roll->stock_lot_id !== $position->stock_lot_id || ! $position->roll->is_active))) {
            InventoryGuard::fail('Roll identity does not match this lot and item.');
        }
        if ($position->lot->expires_on && $position->lot->expires_on->format('Y-m-d') < $date && $picking) {
            InventoryGuard::fail('Expired stock cannot be picked.');
        }
        if ($picking && ($position->lot->quality_status !== 'released' || $position->location->location_type === 'quarantine')) {
            InventoryGuard::fail('Only released stock outside quarantine can be reserved or picked.');
        }
        $lastDate = DB::table('stock_movements as m')->join('documents as d', 'd.id', '=', 'm.document_id')->where('m.stock_position_id', $id)->max('d.document_date');
        if ($lastDate && $date < $lastDate) {
            InventoryGuard::fail('Posting cannot predate the latest movement at a touched position.');
        }
        $this->checkProjection($id);

        return $position;
    }

    public function quantity(StockPosition $position, mixed $input, bool $signed = false): string
    {
        $qty = D::input($input, 6, $signed);
        $places = $position->lot->item->baseUnit->decimal_places;
        if (bccomp($qty, bcadd($qty, '0', $places), 6)) {
            InventoryGuard::fail('Quantity exceeds the base unit measurement precision.');
        }
        if (! bccomp($qty, '0', 6)) {
            InventoryGuard::fail('A movement quantity must be nonzero.');
        }

        return $qty;
    }

    public function plan(int $position, string $quantity, string $date): array
    {
        $remaining = $quantity;
        $plan = [];
        foreach (StockCostLayer::where('stock_position_id', $position)->where('remaining_quantity', '>', 0)->orderBy('fifo_date')->orderBy('receipt_stock_movement_id')->orderBy('id')->lockForUpdate()->get() as $layer) {
            if ($layer->fifo_date->format('Y-m-d') > $date) {
                InventoryGuard::fail('Issue cannot predate its FIFO acquisition.');
            }
            $take = bccomp($remaining, $layer->remaining_quantity, 6) > 0 ? $layer->remaining_quantity : $remaining;
            $plan[] = [$layer, $take];
            $remaining = bcsub($remaining, $take, 6);
            if (bccomp($remaining, '0', 6) === 0) {
                break;
            }
        }
        if (bccomp($remaining, '0', 6) > 0) {
            InventoryGuard::fail('Insufficient FIFO stock.');
        }

        return $plan;
    }

    public function movement(Document $document, int $position, string $quantity, string $reason, ?int $original = null): StockMovement
    {
        return StockMovement::create(['document_id' => $document->id, 'sequence' => 1 + (int) $document->movements()->max('sequence'), 'stock_position_id' => $position, 'quantity_delta' => $quantity, 'movement_reason' => $reason, 'reversal_of_movement_id' => $original]);
    }

    public function allocate(StockMovement $movement, StockCostLayer $layer, string $quantity): InventoryLayerAllocation
    {
        $left = bcadd($layer->fresh()->remaining_quantity, $quantity, 6);
        if (bccomp($left, '0', 6) < 0) {
            InventoryGuard::fail('The selected FIFO layer has already been consumed.');
        }
        $allocation = InventoryLayerAllocation::create(['stock_movement_id' => $movement->id, 'stock_cost_layer_id' => $layer->id, 'quantity_delta' => $quantity]);
        $layer->update(['remaining_quantity' => $left]);

        return $allocation;
    }

    public function value(Document $document, StockCostLayer $layer, InventoryLayerAllocation $allocation, string $amount, string $reason, ?int $original = null): ?InventoryValueEntry
    {
        if (bccomp($amount, '10000000000000000', 4) >= 0 || bccomp($amount, '-10000000000000000', 4) <= 0) {
            InventoryGuard::fail('The evidenced value exceeds the supported money precision.');
        }
        if (bccomp($amount, '0', 4) === 0) {
            return null;
        }

        return InventoryValueEntry::create(['document_id' => $document->id, 'sequence' => 1 + (int) InventoryValueEntry::where('document_id', $document->id)->max('sequence'), 'stock_cost_layer_id' => $layer->id, 'inventory_layer_allocation_id' => $allocation->id, 'amount_pkr' => $amount, 'value_reason' => $reason, 'reversal_of_value_entry_id' => $original]);
    }

    public function receipt(Document $document, StockMovement $movement, string $quantity, string $amount, ?string $fifoDate = null): StockCostLayer
    {
        $layer = StockCostLayer::create(['stock_position_id' => $movement->stock_position_id, 'receipt_stock_movement_id' => $movement->id, 'fifo_date' => $fifoDate ?? $document->document_date->format('Y-m-d'), 'remaining_quantity' => '0']);
        $allocation = $this->allocate($movement, $layer, $quantity);
        $this->value($document, $layer, $allocation, $amount, 'receipt');

        return $layer;
    }

    public function issue(Document $document, StockMovement $movement, StockCostLayer $layer, string $quantity): array
    {
        if ($document->type->module_code !== 'processing' && ProcessingDispatchLine::where('contractor_stock_movement_id', $layer->receipt_stock_movement_id)->exists()) {
            InventoryGuard::fail('Processing custody must be settled through a job receipt or rejected return.');
        }
        $layer->refresh();
        if (bccomp($quantity, $layer->remaining_quantity, 6) > 0) {
            InventoryGuard::fail('FIFO plan is stale; edit the draft to refresh its allocation.');
        }
        $remainingValue = bcadd((string) $layer->values()->sum('amount_pkr'), '0', 4);
        if (bccomp($remainingValue, '0', 4) < 0) {
            InventoryGuard::fail('FIFO carrying value is negative.');
        }
        $amount = bccomp($quantity, $layer->remaining_quantity, 6) === 0 ? $remainingValue : D::round(bcdiv(bcmul($remainingValue, $quantity, 18), $layer->remaining_quantity, 12));
        $allocation = $this->allocate($movement, $layer, D::negative($quantity));
        $this->value($document, $layer, $allocation, D::negative($amount, 4), 'issue');

        return [$allocation, $amount];
    }

    public function link(Document $document, InventoryLayerAllocation $source, string $cost, ?int $target, ?int $expense, string $fraction = '1'): void
    {
        InventoryCostLink::create(['document_id' => $document->id, 'sequence' => 1 + (int) InventoryCostLink::where('document_id', $document->id)->max('sequence'), 'source_layer_allocation_id' => $source->id, 'destination_stock_cost_layer_id' => $target, 'expense_account_id' => $expense, 'allocation_fraction' => $fraction, 'allocated_cost_pkr' => $cost]);
    }

    public function validateRolls(array $positions): void
    {
        $rolls = StockPosition::whereIn('id', $positions)->whereNotNull('fabric_roll_id')->pluck('fabric_roll_id')->unique();
        foreach ($rolls as $roll) {
            $positive = DB::table('stock_movements as m')->join('stock_positions as p', 'p.id', '=', 'm.stock_position_id')->where('p.fabric_roll_id', $roll)->groupBy('p.id')->havingRaw('SUM(m.quantity_delta)>0')->get(['p.id']);
            if ($positive->count() > 1) {
                InventoryGuard::fail('A physical roll cannot be present at two locations. Transfer the entire roll.');
            }
        }
    }
}
