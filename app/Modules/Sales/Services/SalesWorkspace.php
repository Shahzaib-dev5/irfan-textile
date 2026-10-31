<?php

namespace App\Modules\Sales\Services;

use App\Models\Document;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\PartyRole;
use App\Models\PaymentTerm;
use App\Models\SalesCreditNote;
use App\Models\SalesDispatch;
use App\Models\SalesDispatchLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceDispatchMatch;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\StockPosition;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Inventory\Services\InventoryGuard as G;
use App\Modules\Inventory\Services\StockLedger;

class SalesWorkspace
{
    public function __construct(private SalesService $sales, private StockLedger $stock) {}

    public function customers(): mixed
    {
        return PartyRole::with('party', 'customerProfile.paymentTerm')->where('is_active', true)->whereHas('type', fn ($q) => $q->where('code', 'customer')->where('is_active', true))->whereHas('party', fn ($q) => $q->where('is_active', true))->whereHas('customerProfile')->get()->sortBy('party.legal_name');
    }

    public function documents(array $f): mixed
    {
        return Document::with('type')->whereHas('type', fn ($q) => $q->where('module_code', 'sales')->when($f['kind'] ?? null, fn ($q, $k) => $q->where('code', SalesService::TYPES[$k] ?? '')))->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->where('number', 'like', '%'.$v.'%')->orWhere('id', $v)))->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->latest('id')->paginate(20)->withQueryString();
    }

    public function form(string $kind, ?int $customer, ?int $invoice): array
    {
        $sources = [];
        $returns = [];
        if ($customer) {
            abort_unless($this->customers()->contains('id', $customer), 404);
            if ($kind === 'dispatch') {
                foreach (SalesOrderLine::with('order.document', 'item.baseUnit')->whereHas('order', fn ($q) => $q->where('customer_party_role_id', $customer)->whereHas('document', fn ($q) => $q->where('status', 'posted')))->get() as $line) {
                    $left = bcsub($line->base_quantity, $this->sales->dispatched($line->id), 6);
                    if (bccomp($left, '0', 6) > 0 && ! $this->sales->closed($line->document_id)) {
                        $sources[] = ['id' => $line->id, 'item_id' => $line->item_id, 'label' => $line->order->document->number.' / '.$line->item->sku.' / remaining '.$left.' '.$line->item->baseUnit->code, 'remaining' => $left];
                    }
                }
            }
            if (in_array($kind, ['invoice', 'return'])) {
                foreach (SalesDispatchLine::with('dispatch.document', 'orderLine.item.baseUnit')->whereHas('dispatch', fn ($q) => $q->where('customer_party_role_id', $customer)->whereHas('document', fn ($q) => $q->where('status', 'posted')))->get() as $line) {
                    $left = bcsub($line->base_quantity, $kind === 'invoice' ? $this->sales->billed($line->id) : $this->sales->returned($line->id), 6);
                    if (bccomp($left, '0', 6) > 0) {
                        $sources[] = ['id' => $line->id, 'item_id' => $line->orderLine->item_id, 'label' => $line->dispatch->document->number.' / '.$line->orderLine->item->sku.' / remaining '.$left.' '.$line->orderLine->item->baseUnit->code, 'remaining' => $left];
                    }
                }
            }
            if ($kind === 'credit' && $invoice) {
                $inv = SalesInvoice::where('customer_party_role_id', $customer)->whereHas('document', fn ($q) => $q->where('status', 'posted'))->findOrFail($invoice);
                foreach (SalesInvoiceDispatchMatch::with('line.item')->whereHas('line', fn ($q) => $q->where('document_id', $inv->document_id))->get() as $m) {
                    $sources[] = ['id' => $m->id, 'item_id' => $m->line->item_id, 'dispatch_id' => $m->sales_dispatch_line_id, 'label' => 'Match #'.$m->id.' / '.$m->line->item->sku.' / original '.$m->matched_base_quantity.' / PKR '.$m->net_amount, 'remaining' => $m->matched_base_quantity];
                }
                $returns = SalesReturnLine::with('return.document')->whereHas('return', fn ($q) => $q->where('customer_party_role_id', $customer)->whereHas('document', fn ($q) => $q->where('status', 'posted')))->get()->map(fn ($r) => ['id' => $r->id, 'dispatch_id' => $r->sales_dispatch_line_id, 'label' => $r->return->document->number.' / line '.$r->line_no.' / '.$r->base_quantity]);
            }
        }

        return ['kind' => $kind, 'customerId' => $customer, 'invoiceId' => $invoice, 'customers' => $this->customers(), 'terms' => PaymentTerm::where('is_active', true)->get(), 'warehouses' => Warehouse::where('is_active', true)->get(), 'locations' => WarehouseLocation::with('warehouse')->where('is_active', true)->whereIn('location_type', ['internal', 'quarantine'])->whereHas('warehouse', fn ($q) => $q->where('is_active', true))->get(), 'sources' => $sources, 'returnChoices' => $returns, 'positions' => $this->positions(), 'invoices' => $customer ? SalesInvoice::with('document')->where('customer_party_role_id', $customer)->whereHas('document', fn ($q) => $q->where('status', 'posted'))->latest('document_id')->get() : collect()];
    }

    public function positions(): mixed
    {
        return StockPosition::with('lot.item.baseUnit', 'location.warehouse')->whereHas('lot', fn ($q) => $q->where('quality_status', 'released')->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', G::businessDate()))->whereHas('item', fn ($q) => $q->where('item_type', 'finished')->where('is_active', true)->where('is_stocked', true)))->whereHas('location', fn ($q) => $q->where('is_active', true)->where('location_type', 'internal')->whereHas('warehouse', fn ($q) => $q->where('is_active', true)))->get()->map(fn ($p) => ['id' => $p->id, 'item_id' => $p->lot->item_id, 'warehouse_id' => $p->location->warehouse_id, 'on_hand' => $this->stock->onHand($p->id), 'available' => $this->stock->available($p->id), 'label' => $p->lot->item->sku.' / '.$p->lot->lot_number.' / '.$p->location->warehouse->code.'/'.$p->location->code.' / '.$this->stock->available($p->id).' '.$p->lot->item->baseUnit->code])->filter(fn ($p) => bccomp($p['on_hand'], '0', 6) > 0)->values();
    }

    public function items(string $q): mixed
    {
        return Item::with('baseUnit', 'variant.design', 'variant.color', 'conversions.unit')->where('item_type', 'finished')->where('is_active', true)->where('is_stocked', true)->whereHas('baseUnit', fn ($u) => $u->where('is_active', true)->whereIn('code', ['SUIT', 'PACK']))->where(fn ($a) => $a->whereHas('variant', fn ($v) => $v->where('is_active', true))->orWhereHas('baseUnit', fn ($u) => $u->where('code', 'PACK')))->when($q, fn ($a) => $a->where(fn ($a) => $a->where('sku', 'like', '%'.$q.'%')->orWhere('name', 'like', '%'.$q.'%')))->orderBy('sku')->limit(30)->get()->map(fn ($i) => ['id' => $i->id, 'label' => $i->sku.' / '.$i->name, 'unit_id' => $i->base_unit_id, 'unit' => $i->baseUnit->code, 'units' => [['id' => $i->base_unit_id, 'code' => $i->baseUnit->code], ...($i->baseUnit->code === 'PACK' ? [] : $i->conversions->filter(fn ($c) => $c->unit->is_active && $c->unit->code === 'SET')->map(fn ($c) => ['id' => $c->unit_id, 'code' => $c->unit->code])->all())]]);
    }

    public function history(PartyRole $customer): array
    {
        abort_unless($customer->type->code === 'customer' && $customer->customerProfile, 404);
        $ids = SalesOrder::where('customer_party_role_id', $customer->id)->pluck('document_id')->merge(SalesDispatch::where('customer_party_role_id', $customer->id)->pluck('document_id'))->merge(SalesInvoice::where('customer_party_role_id', $customer->id)->pluck('document_id'))->merge(SalesReturn::where('customer_party_role_id', $customer->id)->pluck('document_id'))->merge(SalesCreditNote::whereIn('sales_invoice_id', SalesInvoice::where('customer_party_role_id', $customer->id)->pluck('document_id'))->pluck('document_id'));

        $ledgerQuery = JournalLine::with('document')
            ->where('party_id', $customer->party_id)->where('party_role_id', $customer->id)
            ->whereHas('account', fn ($q) => $q->where('control_type', 'party_receivable'))
            ->whereHas('document', fn ($q) => $q->whereIn('status', ['posted', 'reversed']));
        $balance = (clone $ledgerQuery)->selectRaw('COALESCE(SUM(debit-credit),0) as balance')->value('balance');
        $ledger = $ledgerQuery->orderBy('journal_entry_id')->orderBy('line_no')->paginate(50, ['*'], 'ledger_page')->withQueryString();
        $deliveries = SalesDispatchLine::with('document', 'position.lot.item.baseUnit', 'position.location.warehouse')
            ->whereHas('dispatch', fn ($q) => $q->where('customer_party_role_id', $customer->id))
            ->whereHas('document', fn ($q) => $q->where('status', 'posted'))->latest('id')->paginate(25, ['*'], 'deliveries_page')->withQueryString();

        return ['customer' => $customer->load('party', 'customerProfile.paymentTerm'), 'records' => Document::with('type')->whereIn('id', $ids)->latest('id')->paginate(20)->withQueryString(), 'exposure' => $this->sales->exposure($customer->id), 'customerLedger' => $ledger, 'customerBalance' => $balance, 'deliveries' => $deliveries];
    }

    public function detail(Document $doc): array
    {
        $kind = $this->sales->kind($doc);
        $header = $this->sales->header($doc);
        $customer = $kind === 'credit' ? $header->invoice->customer : $header->customer;
        $progress = [];
        if ($kind === 'order') {
            foreach ($header->lines as $line) {
                $progress[$line->id] = ['dispatched' => $this->sales->dispatched($line->id), 'remaining' => bcsub($line->base_quantity, $this->sales->dispatched($line->id), 6)];
            }
        }

        return ['document' => $doc, 'kind' => $kind, 'header' => $header, 'customer' => $customer->load('party', 'customerProfile'), 'progress' => $progress, 'closed' => $kind === 'order' && $this->sales->closed($doc->id), 'reservations' => StockReservation::where('document_id', $doc->id)->whereRaw('quantity>fulfilled_quantity+released_quantity')->get(), 'positions' => $kind === 'order' ? $this->positions() : collect()];
    }
}
