<?php

namespace App\Modules\Inventory\Services;

use App\Models\Document;
use App\Models\SalesOrderLine;
use App\Models\StockPosition;
use App\Models\StockReservation;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Inventory boundary for commercial reservations. Caller holds the company transaction mutex. */
class SalesReservations
{
    public function __construct(private StockLedger $ledger, private AuditLogger $audit) {}

    private function active(int $document): mixed
    {
        if (! DB::transactionLevel()) {
            throw new \LogicException('Reservation mutations require the posting transaction.');
        }

        return StockReservation::where('document_id', $document)->whereRaw('quantity>fulfilled_quantity+released_quantity');
    }

    public function reserve(int $actor, Document $doc, SalesOrderLine $line, array $data, string $open): void
    {
        $p = $this->ledger->position($data['stock_position_id'], InventoryGuard::businessDate(), true);
        $qty = $this->ledger->quantity($p, $data['quantity']);
        $already = bcadd((string) $this->active($doc->id)->where('sales_order_line_id', $line->id)->sum(DB::raw('quantity-fulfilled_quantity-released_quantity')), '0', 6);
        if ($p->lot->item_id !== $line->item_id || $p->location->location_type !== 'internal' || bccomp(bcadd($already, $qty, 6), $open, 6) > 0 || bccomp($qty, $this->ledger->available($p->id), 6) > 0) {
            InventoryGuard::fail('Reservation must fit the open order line and available internal stock.');
        }
        $r = StockReservation::create(['document_id' => $doc->id, 'sequence' => 1 + (int) StockReservation::where('document_id', $doc->id)->max('sequence'), 'sales_order_line_id' => $line->id, 'stock_position_id' => $p->id, 'quantity' => $qty, 'expires_at' => $data['expires_at'] ?? null]);
        $this->ledger->refresh($p->id);
        $this->audit->record('stock_reservations', $r->id, 'reserved', null, $r->toArray(), $actor, $doc->id);
    }

    public function fulfill(int $actor, SalesOrderLine $line, StockPosition $p, string $qty, string $date): void
    {
        $left = $qty;
        foreach ($this->active($line->document_id)->where('sales_order_line_id', $line->id)->where('stock_position_id', $p->id)->orderBy('id')->lockForUpdate()->get() as $r) {
            if ($r->expires_at && $r->expires_at->isPast()) {
                InventoryGuard::fail('This reservation expired. Release it and reserve again before dispatch.');
            }
            $open = bcsub(bcsub($r->quantity, $r->fulfilled_quantity, 6), $r->released_quantity, 6);
            $take = bccomp($left, $open, 6) < 0 ? $left : $open;
            $before = $r->toArray();
            $r->update(['fulfilled_quantity' => bcadd($r->fulfilled_quantity, $take, 6)]);
            $this->audit->record('stock_reservations', $r->id, 'fulfilled', $before, $r->fresh()->toArray(), $actor, $line->document_id);
            $left = bcsub($left, $take, 6);
            if (! bccomp($left, '0', 6)) {
                break;
            }
        }
        $this->ledger->refresh($p->id);
    }

    public function release(int $actor, Document $doc, ?int $line, string $reason): void
    {
        foreach ($this->active($doc->id)->when($line, fn ($q) => $q->where('sales_order_line_id', $line))->orderBy('stock_position_id')->lockForUpdate()->get() as $r) {
            $this->ledger->checkProjection($r->stock_position_id);
            $before = $r->toArray();
            $r->update(['released_quantity' => bcsub($r->quantity, $r->fulfilled_quantity, 6)]);
            $this->ledger->refresh($r->stock_position_id);
            $this->audit->record('stock_reservations', $r->id, 'released', $before, ['reservation' => $r->fresh()->toArray(), 'reason' => $reason], $actor, $doc->id);
        }
    }
}
