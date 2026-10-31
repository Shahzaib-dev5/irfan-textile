<?php

namespace App\Modules\FinishedGoods\Services;

use App\Models\StockLot;
use App\Models\StockPosition;
use App\Modules\Inventory\Services\InventoryGuard as G;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Support\Decimal as D;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class FinishedGoodsQuality
{
    public function apply(int $actor, array $d): string
    {
        G::actor($actor, 'finished-goods.quality');

        return DB::transaction(function () use ($actor, $d) {
            G::company();
            $s = app(InventoryService::class);
            $lot = StockLot::with('item')->findOrFail($d['stock_lot_id']);
            abort_unless($lot->item->item_type === 'finished', 404);
            if ($d['action'] === 'quality') {
                $s->quality($actor, $lot->id, $d['quality_status'], $d['reason']);

                return 'Finished lot quality updated with audit history.';
            }
            if ($d['action'] !== 'damage') {
                G::fail('Choose inspection or actual damage disposal.');
            }
            $position = StockPosition::findOrFail($d['stock_position_id']);
            abort_unless($position->stock_lot_id === $lot->id, 404);
            $doc = $s->save($actor, ['kind' => 'adjustment', 'document_date' => $d['document_date'], 'idempotency_key' => $d['idempotency_key'], 'adjustment_reason' => 'damage', 'explanation' => $d['reason'], 'lines' => [['stock_position_id' => $d['stock_position_id'], 'quantity_delta' => D::negative(D::input($d['quantity']))]]]);
            $doc = $s->post($actor, $doc->id, (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'textile:finished-damage:'.$d['idempotency_key']), $doc->lock_version);

            return 'Actual damage posted through Inventory: '.$doc->number;
        }, 3);
    }
}
