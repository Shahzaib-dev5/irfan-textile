<?php

namespace App\Modules\Sales\Services;

use App\Models\AuditEvent;
use App\Models\CustomerProfile;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\InventoryLayerAllocation;
use App\Models\InventoryValueEntry;
use App\Models\Item;
use App\Models\LedgerAccount;
use App\Models\PartyAddress;
use App\Models\PartyRole;
use App\Models\PaymentTerm;
use App\Models\PostingAccount;
use App\Models\SalesCreditNote;
use App\Models\SalesCreditNoteLine;
use App\Models\SalesDispatch;
use App\Models\SalesDispatchLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceDispatchMatch;
use App\Models\SalesInvoiceLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesReturn;
use App\Models\SalesReturnLine;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockPosition;
use App\Models\StockReservation;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Modules\Accounting\Services\SourceJournal;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Inventory\Services\InventoryGuard as G;
use App\Modules\Inventory\Services\SalesReservations;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Support\Decimal as D;
use App\Modules\Purchasing\Services\PurchaseDocuments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Sales owns commercial caps; Inventory owns all physical/FIFO posting primitives. */
class SalesService
{
    public const HEADERS = ['order' => SalesOrder::class, 'dispatch' => SalesDispatch::class, 'invoice' => SalesInvoice::class, 'return' => SalesReturn::class, 'credit' => SalesCreditNote::class];

    public const TYPES = ['order' => 'sales_order', 'dispatch' => 'sales_dispatch', 'invoice' => 'sales_invoice', 'return' => 'sales_return', 'credit' => 'sales_credit_note'];

    public function __construct(private StockLedger $stock, private PurchaseDocuments $documents, private SourceJournal $journals, private AuditLogger $audit, private SalesReservations $reservations) {}

    public static function rules(): array
    {
        return [
            'kind' => 'required|in:order,dispatch,invoice,return,credit', 'document_date' => 'required|date_format:Y-m-d', 'idempotency_key' => 'required|uuid',
            'customer_party_role_id' => 'required|integer|exists:customer_profiles,party_role_id', 'payment_term_id' => 'nullable|integer|exists:payment_terms,id',
            'warehouse_id' => 'required_if:kind,dispatch,return|nullable|integer|exists:warehouses,id', 'customer_reference' => 'nullable|string|max:100', 'required_on' => 'nullable|date_format:Y-m-d',
            'ship_to_address_id' => 'nullable|integer|exists:party_addresses,id', 'ship_to_snapshot' => 'nullable|string|max:3000', 'billing_address_snapshot' => 'nullable|string|max:3000',
            'notes' => 'nullable|string|max:5000', 'carrier' => 'nullable|string|max:150', 'tracking_reference' => 'nullable|string|max:100',
            'due_on' => 'nullable|date_format:Y-m-d|after_or_equal:document_date', 'reason' => 'required_if:kind,return,credit|nullable|string|min:5|max:500',
            'sales_invoice_id' => 'required_if:kind,credit|nullable|integer|exists:sales_invoices,document_id', 'credit_kind' => 'required_if:kind,credit|nullable|in:physical_return,price_adjustment',
            'lines' => 'required|array|min:1|max:100', 'lines.*' => 'required|array',
            'lines.*.item_id' => 'required_if:kind,order|nullable|integer|exists:items,id', 'lines.*.unit_id' => 'nullable|integer|exists:units,id', 'lines.*.quantity' => 'required_if:kind,order|nullable|decimal:0,6|gt:0',
            'lines.*.unit_price' => 'required_if:kind,order|nullable|decimal:0,8|gte:0', 'lines.*.discount_amount' => 'nullable|decimal:0,4|gte:0', 'lines.*.description' => 'nullable|string|max:255',
            'lines.*.sales_order_line_id' => 'required_if:kind,dispatch|nullable|integer|exists:sales_order_lines,id', 'lines.*.stock_position_id' => 'required_if:kind,dispatch|nullable|integer|exists:stock_positions,id',
            'lines.*.base_quantity' => 'required_if:kind,dispatch,invoice,return|nullable|decimal:0,6|gt:0', 'lines.*.sales_dispatch_line_id' => 'required_if:kind,invoice,return|nullable|integer|exists:sales_dispatch_lines,id',
            'lines.*.warehouse_location_id' => 'required_if:kind,return|nullable|integer|exists:warehouse_locations,id', 'lines.*.lot_number' => 'required_if:kind,return|nullable|string|max:50',
            'lines.*.condition_code' => 'nullable|in:saleable,quarantine,damaged', 'lines.*.sales_invoice_dispatch_match_id' => 'required_if:kind,credit|nullable|integer|exists:sales_invoice_dispatch_matches,id',
            'lines.*.sales_return_line_id' => 'required_if:credit_kind,physical_return|nullable|integer|exists:sales_return_lines,id', 'lines.*.credited_base_quantity' => 'nullable|decimal:0,6|gte:0',
            'lines.*.net_amount' => 'required_if:kind,credit|nullable|decimal:0,4|gt:0',
            'tax_amount' => 'prohibited', 'rounding_amount' => 'prohibited', 'lines.*.tax_rate_id' => 'prohibited', 'lines.*.packing_package_id' => 'prohibited',
        ];
    }

    private function transaction(int $actor, string $permission, callable $action): mixed
    {
        return DB::transaction(function () use ($actor, $permission, $action) {
            G::company();
            G::actor($actor, $permission);

            return $action();
        }, 3);
    }

    public function kind(Document $doc): string
    {
        $kind = array_search($doc->type->code, self::TYPES, true);
        abort_unless($doc->type->module_code === 'sales' && $kind !== false, 404);

        return $kind;
    }

    public function header(Document $doc): mixed
    {
        return (self::HEADERS[$this->kind($doc)])::with('lines')->findOrFail($doc->id);
    }

    private function customer(int $id): PartyRole
    {
        $role = PartyRole::with('party', 'customerProfile', 'type')->lockForUpdate()->findOrFail($id);
        $role->setRelation('customerProfile', CustomerProfile::whereKey($id)->lockForUpdate()->first());
        if (! $role->is_active || ! $role->party->is_active || ! $role->type->is_active || $role->type->code !== 'customer' || ! $role->customerProfile) {
            G::fail('Choose an active shared Customer role and profile.', 'customer');
        }

        return $role;
    }

    private function account(string $purpose): int
    {
        $account = LedgerAccount::find(PostingAccount::where('purpose_code', $purpose)->value('ledger_account_id'));
        $control = $purpose === 'customer_receivable' ? 'party_receivable' : ($purpose === 'inventory' ? 'inventory' : 'none');
        $type = match ($purpose) {
            'sales_revenue' => 'income','sales_cogs','sales_returns' => 'expense',default => 'asset'
        };
        if (! $account || ! $account->is_active || ! $account->is_postable || $account->control_type !== $control || $account->account_type !== $type) {
            G::fail('Configure a valid posting account: '.$purpose);
        }

        return $account->id;
    }

    public function closed(int $order): bool
    {
        return AuditEvent::where('document_id', $order)->where('action', 'sales_order_closed')->exists();
    }

    private function effective(string $table, string $field, int $id, string $sum): string
    {
        return bcadd((string) DB::table($table.' as l')->join('documents as d', 'd.id', '=', 'l.document_id')->where('l.'.$field, $id)->where('d.status', 'posted')->sum('l.'.$sum), '0', 6);
    }

    public function dispatched(int $line): string
    {
        return $this->effective('sales_dispatch_lines', 'sales_order_line_id', $line, 'base_quantity');
    }

    public function returned(int $line): string
    {
        return $this->effective('sales_return_lines', 'sales_dispatch_line_id', $line, 'base_quantity');
    }

    public function billed(int $line): string
    {
        return bcadd((string) DB::table('sales_invoice_dispatch_matches as m')->join('sales_invoice_lines as l', 'l.id', '=', 'm.sales_invoice_line_id')->join('documents as d', 'd.id', '=', 'l.document_id')->where('m.sales_dispatch_line_id', $line)->where('d.status', 'posted')->sum('m.matched_base_quantity'), '0', 6);
    }

    public function net(SalesOrderLine $line): string
    {
        return bcsub(D::round(bcmul($line->quantity, $line->unit_price, 14)), $line->discount_amount, 4);
    }

    private function proportion(string $amount, string $quantity, string $whole): string
    {
        return bccomp($quantity, $whole, 6) === 0 ? bcadd($amount, '0', 4) : D::round(bcdiv(bcmul($amount, $quantity, 18), $whole, 12));
    }

    /** Includes AR, unbilled dispatch and approved unfulfilled commitments exactly once. */
    public function exposure(int $role): string
    {
        $total = bcadd((string) DB::table('journal_lines as l')->join('ledger_accounts as a', 'a.id', '=', 'l.ledger_account_id')->join('documents as d', 'd.id', '=', 'l.journal_entry_id')->where('l.party_role_id', $role)->whereIn('d.status', ['posted', 'reversed'])->where(function ($q) {
            $q->where('a.control_type', 'party_receivable')->orWhere('a.id', $this->account('unbilled_receivable'));
        })->sum(DB::raw('l.debit-l.credit')), '0', 4);
        foreach (SalesOrder::with('lines')->where('customer_party_role_id', $role)->whereHas('document', fn ($q) => $q->where('status', 'posted'))->get() as $order) {
            if ($this->closed($order->document_id)) {
                continue;
            }
            foreach ($order->lines as $line) {
                $sent = $this->dispatched($line->id);
                $total = bcadd($total, bcsub($this->net($line), $this->proportion($this->net($line), $sent, $line->base_quantity), 4), 4);
            }
        }

        return $total;
    }

    private function credit(PartyRole $customer, string $new = '0'): void
    {
        if ($customer->customerProfile->credit_hold) {
            G::fail('This customer is on credit hold. Ask the administrator to review it.', 'credit');
        }
        if (bccomp(bcadd($this->exposure($customer->id), $new, 4), $customer->customerProfile->credit_limit, 4) > 0) {
            G::fail('Customer credit limit exceeded. Reduce the order or ask the administrator to review the limit.', 'credit');
        }
    }

    private function orderLine(int $id, int $customer, string $date, bool $requireOpen = true): SalesOrderLine
    {
        $line = SalesOrderLine::with('order.document', 'item.baseUnit', 'unit')->lockForUpdate()->findOrFail($id);
        if ($line->order->customer_party_role_id !== $customer || $line->order->document->status !== 'posted' || $line->order->document->document_date->format('Y-m-d') > $date || ($requireOpen && $this->closed($line->document_id))) {
            G::fail('Choose a confirmed open sales order for this customer and date.');
        }

        return $line;
    }

    private function dispatchLine(int $id, int $customer, string $date): SalesDispatchLine
    {
        $line = SalesDispatchLine::with('dispatch.document', 'orderLine.item.baseUnit', 'position.lot')->lockForUpdate()->findOrFail($id);
        if ($line->dispatch->customer_party_role_id !== $customer || $line->dispatch->document->status !== 'posted' || $line->dispatch->document->document_date->format('Y-m-d') > $date) {
            G::fail('Choose a confirmed dispatch for this customer and date.');
        }

        return $line;
    }

    public function save(int $actor, array $input): Document
    {
        return $this->transaction($actor, 'sales.documents.manage', function () use ($actor, $input) {
            $data = validator($input, self::rules())->validate();
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if ($existing = Document::where('idempotency_key', $data['idempotency_key'])->first()) {
                $this->kind($existing);
                if ((AuditEvent::where('document_id', $existing->id)->where('action', 'sales_draft_created')->first()?->after_json['input_hash'] ?? null) !== $hash) {
                    G::fail('Creation key was reused with different data.');
                }

                return $existing;
            }
            [$year,$period] = $this->documents->calendar($data['document_date']);
            $customer = $this->customer($data['customer_party_role_id']);
            $termId = $data['payment_term_id'] ?? $customer->customerProfile->payment_term_id;
            $term = $termId ? PaymentTerm::where('is_active', true)->findOrFail($termId) : null;
            if (in_array($data['kind'], ['dispatch', 'return']) && ! Warehouse::whereKey($data['warehouse_id'])->where('is_active', true)->exists()) {
                G::fail('Choose an active warehouse.');
            }
            $type = DocumentType::where('code', self::TYPES[$data['kind']])->where('is_active', true)->firstOrFail();
            $doc = Document::create(['document_type_id' => $type->id, 'document_date' => $data['document_date'], 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id, 'created_by' => $actor, 'idempotency_key' => $data['idempotency_key'], 'status' => 'draft', 'lock_version' => 0]);
            $common = ['document_id' => $doc->id, 'customer_party_role_id' => $customer->id];
            $address = $data['ship_to_snapshot'] ?? $customer->party->legal_name;
            if (! empty($data['ship_to_address_id'])) {
                $a = PartyAddress::where('party_id', $customer->party_id)->where('is_active', true)->findOrFail($data['ship_to_address_id']);
                $address = implode(', ', array_filter([$customer->party->legal_name, $a->address_line_1, $a->address_line_2, $a->city, $a->country_code]));
            }
            $notes = $data['notes'] ?? null;
            switch ($data['kind']) {
                case 'order':
                    SalesOrder::create($common + ['payment_term_id' => $term?->id, 'customer_reference' => $data['customer_reference'] ?? null, 'required_on' => $data['required_on'] ?? null, 'ship_to_address_id' => $data['ship_to_address_id'] ?? null, 'ship_to_snapshot' => $address, 'notes' => $notes]);
                    foreach ($data['lines'] as $i => $row) {
                        $item = Item::with('baseUnit', 'variant')->findOrFail($row['item_id'] ?? 0);
                        if (! $item->is_active || ! $item->is_stocked || $item->item_type !== 'finished' || ! $item->baseUnit->is_active || ! in_array($item->baseUnit->code, ['SUIT', 'PACK']) || ($item->baseUnit->code === 'SUIT' && (! $item->variant || ! $item->variant->is_active))) {
                            G::fail('Wholesale items must be active finished SUIT variants or PACK SKUs.');
                        }
                        $unit = Unit::where('is_active', true)->findOrFail($row['unit_id'] ?? $item->base_unit_id);
                        $factor = $unit->id === $item->base_unit_id ? '1' : $item->conversions()->where('unit_id', $unit->id)->value('factor_to_base');
                        if (! $factor || $unit->dimension !== $item->baseUnit->dimension || bccomp($factor, bcadd($factor, '0', 0), 10) || bccomp($factor, '1', 10) < 0 || ($item->baseUnit->code === 'PACK' && $unit->id !== $item->base_unit_id) || ($unit->id !== $item->base_unit_id && $unit->code !== 'SET')) {
                            G::fail('Invalid sales unit conversion. PACK and SUIT are separate products.');
                        }
                        if (bccomp($factor, bcadd($factor, '0', 8), 10)) {
                            G::fail('Sales conversion exceeds eight decimal places.');
                        }
                        $qty = D::input($row['quantity'] ?? '0');
                        $base = bcmul($qty, $factor, 6);
                        if (bccomp($qty, '0', 6) <= 0 || bccomp($qty, bcadd($qty, '0', $unit->decimal_places), 6) || bccomp(bcmul($qty, $factor, 16), $base, 16) || bccomp($base, bcadd($base, '0', 0), 6) || bccomp($base, '99999999999999.999999', 6) > 0) {
                            G::fail('Sales quantities must represent whole finished suits or packs.');
                        }
                        $price = D::input($row['unit_price'] ?? '0', 8);
                        $discount = D::input($row['discount_amount'] ?? '0', 4);
                        if (bccomp($discount, D::round(bcmul($qty, $price, 14)), 4) > 0) {
                            G::fail('Discount cannot exceed the line value.');
                        }
                        SalesOrderLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'item_id' => $item->id, 'description' => $row['description'] ?? $item->name, 'unit_id' => $unit->id, 'quantity' => $qty, 'base_quantity' => $base, 'conversion_factor' => $factor, 'unit_price' => $price, 'discount_amount' => $discount, 'required_on' => $data['required_on'] ?? null]);
                    }
                    break;
                case 'dispatch':
                    SalesDispatch::create($common + ['warehouse_id' => $data['warehouse_id'], 'dispatched_at' => $data['document_date'].' 12:00:00', 'carrier' => $data['carrier'] ?? null, 'tracking_reference' => $data['tracking_reference'] ?? null, 'ship_to_snapshot' => $address, 'notes' => $notes]);
                    foreach ($data['lines'] as $i => $row) {
                        $order = $this->orderLine($row['sales_order_line_id'] ?? 0, $customer->id, $data['document_date']);
                        SalesDispatchLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'sales_order_line_id' => $order->id, 'stock_position_id' => $row['stock_position_id'] ?? 0, 'base_quantity' => D::input($row['base_quantity'] ?? '0'), 'net_revenue_amount' => 0, 'revenue_account_id' => $this->account('sales_revenue'), 'description' => $order->description]);
                    }
                    break;
                case 'invoice':
                    $due = $data['due_on'] ?? Carbon::parse($data['document_date'])->addDays($term?->due_days ?? 0)->format('Y-m-d');
                    $header = SalesInvoice::create($common + ['payment_term_id' => $term?->id, 'due_on' => $due, 'billing_address_snapshot' => $data['billing_address_snapshot'] ?? $address, 'customer_reference' => $data['customer_reference'] ?? null, 'net_amount' => '0.0001', 'gross_amount' => '0.0001', 'notes' => $notes]);
                    foreach ($data['lines'] as $i => $row) {
                        $dispatch = $this->dispatchLine($row['sales_dispatch_line_id'] ?? 0, $customer->id, $data['document_date']);
                        $order = $dispatch->orderLine;
                        $qty = D::input($row['base_quantity'] ?? '0');
                        // Invoice measured in the base unit; dispatch determines its exact net value at posting.
                        $line = SalesInvoiceLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'sales_order_line_id' => $order->id, 'item_id' => $order->item_id, 'description' => $order->description, 'unit_id' => $order->item->base_unit_id, 'quantity' => $qty, 'base_quantity' => $qty, 'conversion_factor' => 1, 'unit_price' => 0, 'net_amount' => 0, 'gross_amount' => 0]);
                        SalesInvoiceDispatchMatch::create(['sales_invoice_line_id' => $line->id, 'sales_dispatch_line_id' => $dispatch->id, 'matched_base_quantity' => $qty, 'net_amount' => 0]);
                    }
                    $this->priceInvoice($doc, $header, $customer);
                    break;
                case 'return':
                    SalesReturn::create($common + ['warehouse_id' => $data['warehouse_id'], 'received_at' => $data['document_date'].' 12:00:00', 'reason' => $data['reason']]);
                    foreach ($data['lines'] as $i => $row) {
                        $dispatch = $this->dispatchLine($row['sales_dispatch_line_id'] ?? 0, $customer->id, $data['document_date']);
                        $location = WarehouseLocation::with('warehouse')->findOrFail($row['warehouse_location_id'] ?? 0);
                        $condition = $row['condition_code'] ?? 'quarantine';
                        if (! $location->is_active || ! $location->warehouse->is_active || $location->warehouse_id !== (int) $data['warehouse_id'] || ! in_array($location->location_type, ['internal', 'quarantine']) || ($condition !== 'saleable' && $location->location_type !== 'quarantine') || ($condition === 'saleable' && $location->location_type !== 'internal')) {
                            G::fail('Saleable returns need internal storage; other returns need quarantine.');
                        }
                        $lotNo = $row['lot_number'] ?? '';
                        if (! $lotNo || StockLot::where('item_id', $dispatch->orderLine->item_id)->where('lot_number', $lotNo)->exists()) {
                            G::fail('Enter a new, unique return lot number.');
                        }
                        $lot = StockLot::create(['item_id' => $dispatch->orderLine->item_id, 'lot_number' => $lotNo, 'origin_document_id' => $doc->id, 'quality_status' => $condition === 'saleable' ? 'released' : 'held', 'external_lot_reference' => 'Returned from '.$dispatch->position->lot->lot_number]);
                        $position = StockPosition::create(['stock_lot_id' => $lot->id, 'warehouse_location_id' => $location->id]);
                        StockBalance::create(['stock_position_id' => $position->id, 'on_hand_quantity' => 0, 'reserved_quantity' => 0]);
                        SalesReturnLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'sales_dispatch_line_id' => $dispatch->id, 'stock_position_id' => $position->id, 'base_quantity' => D::input($row['base_quantity'] ?? '0'), 'condition_code' => $condition, 'reason' => $data['reason']]);
                    }
                    break;
                case 'credit':
                    $invoice = SalesInvoice::with('document')->lockForUpdate()->findOrFail($data['sales_invoice_id']);
                    if ($invoice->customer_party_role_id !== $customer->id || $invoice->document->status !== 'posted' || $invoice->document->document_date->format('Y-m-d') > $data['document_date']) {
                        G::fail('Choose this customer’s confirmed invoice.');
                    }
                    $header = SalesCreditNote::create(['document_id' => $doc->id, 'sales_invoice_id' => $invoice->document_id, 'credit_kind' => $data['credit_kind'], 'reason' => $data['reason'], 'net_amount' => '0.0001', 'gross_amount' => '0.0001']);
                    foreach ($data['lines'] as $i => $row) {
                        $match = SalesInvoiceDispatchMatch::with('line')->findOrFail($row['sales_invoice_dispatch_match_id'] ?? 0);
                        if ($match->line->document_id !== $invoice->document_id) {
                            G::fail('Credit lines must match the selected invoice.');
                        }
                        SalesCreditNoteLine::create(['document_id' => $doc->id, 'line_no' => $i + 1, 'sales_invoice_line_id' => $match->sales_invoice_line_id, 'sales_invoice_dispatch_match_id' => $match->id, 'sales_return_line_id' => $row['sales_return_line_id'] ?? null, 'credited_base_quantity' => D::input($row['credited_base_quantity'] ?? '0'), 'net_amount' => D::input($row['net_amount'] ?? '0', 4), 'gross_amount' => D::input($row['net_amount'] ?? '0', 4), 'description' => $row['description'] ?? $data['reason']]);
                    }
                    $this->validateCredits($header, $data['document_date']);
                    break;
            }
            $this->audit->record('documents', $doc->id, 'sales_draft_created', null, ['input_hash' => $hash, 'payload_hash' => $this->hash($doc)], $actor, $doc->id);
            $this->documents->event($doc, 'created', $actor, $doc->idempotency_key, null, 'draft');

            return $doc->fresh();
        });
    }

    private function hash(Document $doc): string
    {
        $h = $this->header($doc);
        $header = $h->getAttributes();
        unset($header['created_at'],$header['updated_at']);
        $lines = [];
        foreach ($h->lines as $line) {
            $a = $line->getAttributes();
            unset($a['created_at'],$a['updated_at']);
            if ($line instanceof SalesInvoiceLine) {
                $a['matches'] = $line->matches->map->getAttributes()->all();
            } $lines[] = $a;
        }

        return hash('sha256', json_encode(['date' => $doc->document_date->format('Y-m-d'), 'header' => $header, 'lines' => $lines], JSON_THROW_ON_ERROR));
    }

    public function confirm(int $actor, array $input): Document
    {
        return $this->transaction($actor, 'sales.documents.confirm', function () use ($actor, $input) {
            $doc = $this->save($actor, $input);

            return $this->post($actor, $doc->id, $input['idempotency_key'], $doc->lock_version);
        });
    }

    public function post(int $actor, int $id, string $key, int $version): Document
    {
        validator(['key' => $key], ['key' => 'required|uuid'])->validate();

        return $this->transaction($actor, 'sales.documents.confirm', function () use ($actor, $id, $key, $version) {
            $doc = Document::with('type')->lockForUpdate()->findOrFail($id);
            $kind = $this->kind($doc);
            if ($doc->status === 'posted' && $doc->posting_key === $key && hash_equals($doc->getRawOriginal('payload_hash'), hex2bin($this->hash($doc)))) {
                return $doc;
            }
            if ($doc->status !== 'draft' || $doc->lock_version !== $version) {
                G::fail('This entry changed or is already confirmed. Reload it.');
            }
            if (Document::where('posting_key', $key)->exists()) {
                G::fail('Posting request key is already used.');
            }
            $this->documents->calendar($doc->document_date->format('Y-m-d'));
            $h = $this->header($doc);
            if ($h->lines->isEmpty()) {
                G::fail('A sales document requires at least one line.');
            }
            $customer = $this->customer($kind === 'credit' ? $h->invoice->customer_party_role_id : $h->customer_party_role_id);
            $due = $h->due_on?->format('Y-m-d') ?? $doc->document_date->format('Y-m-d');
            if ($kind === 'order') {
                $value = '0';
                foreach ($h->lines as $line) {
                    $item = $line->item->load('baseUnit', 'variant');
                    if (! $item->is_active || ! $item->is_stocked || $item->item_type !== 'finished' || ! $item->baseUnit->is_active || ! $line->unit->is_active || ! in_array($item->baseUnit->code, ['SUIT', 'PACK']) || ($item->baseUnit->code === 'SUIT' && (! $item->variant || ! $item->variant->is_active))) {
                        G::fail('Order items and sales units must remain active finished products.');
                    }
                    $value = bcadd($value, $this->net($line), 4);
                }
                $this->credit($customer, $value);
            } elseif ($kind === 'dispatch') {
                $this->credit($customer);
                $this->postDispatch($actor, $doc, $h, $customer);
            } elseif ($kind === 'invoice') {
                $this->priceInvoice($doc, $h, $customer);
                $h->refresh();
                $this->journals->post($doc, [$this->account('customer_receivable') => $h->net_amount, $this->account('unbilled_receivable') => D::negative($h->net_amount, 4)], 'operational', $customer->party_id, $customer->id, $due);
            } elseif ($kind === 'return') {
                $this->postReturn($doc, $h, $customer);
            } else {
                $this->validateCredits($h, $doc->document_date->format('Y-m-d'));
                $h->refresh();
                $this->journals->post($doc, [$this->account('sales_returns') => $h->net_amount, $this->account('customer_receivable') => D::negative($h->net_amount, 4)], 'operational', $customer->party_id, $customer->id, $due);
            }
            $this->documents->finish($doc, $actor, $key, hex2bin($this->hash($doc)));

            return $doc->fresh();
        });
    }

    private function postDispatch(int $actor, Document $doc, SalesDispatch $header, PartyRole $customer): void
    {
        $date = $doc->document_date->format('Y-m-d');
        $cost = '0';
        $revenue = '0';
        $revenueAccounts = [];
        $positions = [];
        foreach ($header->lines as $line) {
            $order = $this->orderLine($line->sales_order_line_id, $customer->id, $date);
            $p = $this->stock->position($line->stock_position_id, $date, true);
            $qty = $this->stock->quantity($p, $line->base_quantity);
            if ($p->lot->item_id !== $order->item_id || $p->location->warehouse_id !== $header->warehouse_id || $p->location->location_type !== 'internal') {
                G::fail('Dispatch stock must match the order item at internal storage in the selected warehouse.');
            }
            $before = $this->dispatched($order->id);
            $after = bcadd($before, $qty, 6);
            // Include preceding lines of this still-draft dispatch in caps and revenue rounding.
            foreach ($header->lines->where('line_no', '<', $line->line_no)->where('sales_order_line_id', $order->id) as $prior) {
                $before = bcadd($before, $prior->base_quantity, 6);
            }
            $after = bcadd($before, $qty, 6);
            if (bccomp($after, $order->base_quantity, 6) > 0) {
                G::fail('Dispatch exceeds the unfulfilled order balance.');
            }
            $this->reservations->fulfill($actor, $order, $p, $qty, $date);
            $held = (string) StockReservation::where('sales_order_line_id', $order->id)->sum(DB::raw('quantity-fulfilled_quantity-released_quantity'));
            if (bccomp($held, bcsub($order->base_quantity, $after, 6), 6) > 0) {
                G::fail('Other lots remain reserved above the remaining order balance. Release those reservations before dispatching another lot.');
            }
            if (bccomp($qty, $this->stock->available($p->id), 6) > 0) {
                G::fail('Insufficient unreserved stock for this dispatch.');
            }
            $amount = bcsub($this->proportion($this->net($order), $after, $order->base_quantity), $this->proportion($this->net($order), $before, $order->base_quantity), 4);
            $revenueAccount = LedgerAccount::findOrFail($line->revenue_account_id);
            if (! $revenueAccount->is_active || ! $revenueAccount->is_postable || $revenueAccount->account_type !== 'income' || $revenueAccount->control_type !== 'none') {
                G::fail('The frozen dispatch revenue account is inactive or invalid. Cancel and recreate the draft after reviewing account configuration.');
            }
            $movement = $this->stock->movement($doc, $p->id, D::negative($qty), 'sales_dispatch');
            foreach ($this->stock->plan($p->id, $qty, $date) as [$layer,$take]) {
                [$allocation,$money] = $this->stock->issue($doc, $movement, $layer, $take);
                $this->stock->link($doc, $allocation, $money, null, $this->account('sales_cogs'));
                $cost = bcadd($cost, $money, 4);
            }
            $line->update(['stock_movement_id' => $movement->id, 'net_revenue_amount' => $amount]);
            $revenue = bcadd($revenue, $amount, 4);
            $revenueAccounts[$revenueAccount->id] = bcsub($revenueAccounts[$revenueAccount->id] ?? '0', $amount, 4);
            $this->stock->refresh($p->id);
            $positions[] = $p->id;
        }
        $this->stock->validateRolls($positions);
        $this->journals->post($doc, [$this->account('sales_cogs') => $cost, $this->account('inventory') => D::negative($cost, 4), $this->account('unbilled_receivable') => $revenue] + $revenueAccounts, 'operational', $customer->party_id, $customer->id, $date);
    }

    private function priceInvoice(Document $doc, SalesInvoice $header, PartyRole $customer): void
    {
        $total = '0';
        $used = [];
        foreach ($header->lines as $line) {
            $match = $line->matches->sole();
            $dispatch = $this->dispatchLine($match->sales_dispatch_line_id, $customer->id, $doc->document_date->format('Y-m-d'));
            $qty = $match->matched_base_quantity;
            if (bccomp($qty, '0', 6) <= 0 || bccomp($qty, bcadd($qty, '0', 0), 6)) {
                G::fail('Invoice quantities must be whole suits or packs.');
            }
            $before = bcadd($this->billed($dispatch->id), $used[$dispatch->id] ?? '0', 6);
            $after = bcadd($before, $qty, 6);
            if (bccomp($after, $dispatch->base_quantity, 6) > 0) {
                G::fail('Invoice exceeds the dispatch quantity still available for billing.');
            }
            $net = bcsub($this->proportion($dispatch->net_revenue_amount, $after, $dispatch->base_quantity), $this->proportion($dispatch->net_revenue_amount, $before, $dispatch->base_quantity), 4);
            $order = $dispatch->orderLine;
            $price = bcdiv($order->unit_price, $order->conversion_factor, 8);
            $gross = D::round(bcmul($qty, $price, 14));
            // A repeating base-unit price may not reproduce the exact frozen dispatch cents.
            // Snapshotted price rounds upward just enough to represent an explicit residual discount.
            if (bccomp($gross, $net, 4) < 0) {
                $price = bcadd(bcdiv($net, $qty, 8), '0.00000001', 8);
                $gross = D::round(bcmul($qty, $price, 14));
            }
            $line->update(['unit_price' => $price, 'discount_amount' => bcsub($gross, $net, 4), 'net_amount' => $net, 'gross_amount' => $net]);
            $match->update(['net_amount' => $net]);
            $total = bcadd($total, $net, 4);
            $used[$dispatch->id] = bcadd($used[$dispatch->id] ?? '0', $qty, 6);
        }
        if (bccomp($total, '0', 4) <= 0) {
            G::fail('An invoice must have a positive matched value. Include free goods with priced goods.');
        }
        $header->update(['net_amount' => $total, 'gross_amount' => $total]);
    }

    private function postReturn(Document $doc, SalesReturn $header, PartyRole $customer): void
    {
        $date = $doc->document_date->format('Y-m-d');
        $total = '0';
        $used = [];
        foreach ($header->lines as $line) {
            $dispatch = $this->dispatchLine($line->sales_dispatch_line_id, $customer->id, $date);
            $p = $this->stock->position($line->stock_position_id, $date);
            $qty = $this->stock->quantity($p, $line->base_quantity);
            if ($p->lot->origin_document_id !== $doc->id || $p->lot->item_id !== $dispatch->orderLine->item_id || $p->location->warehouse_id !== $header->warehouse_id || ! in_array($p->location->location_type, ['internal', 'quarantine']) || ($line->condition_code !== 'saleable' && ($p->location->location_type !== 'quarantine' || $p->lot->quality_status === 'released'))) {
                G::fail('Return stock provenance or quality storage is invalid.');
            }
            $before = bcadd($this->returned($dispatch->id), $used[$dispatch->id] ?? '0', 6);
            $after = bcadd($before, $qty, 6);
            if (bccomp($after, $dispatch->base_quantity, 6) > 0) {
                G::fail('Return exceeds the originally dispatched quantity.');
            }
            $cost = '0';
            $links = [];
            foreach (InventoryLayerAllocation::where('stock_movement_id', $dispatch->stock_movement_id)->where('quantity_delta', '<', 0)->get() as $a) {
                $issued = D::negative((string) InventoryValueEntry::where('inventory_layer_allocation_id', $a->id)->sum('amount_pkr'), 4);
                $amount = bcsub($this->proportion($issued, $after, $dispatch->base_quantity), $this->proportion($issued, $before, $dispatch->base_quantity), 4);
                $cost = bcadd($cost, $amount, 4);
                $links[] = [$a, $amount];
            }
            $movement = $this->stock->movement($doc, $p->id, $qty, 'sales_return');
            $layer = $this->stock->receipt($doc, $movement, $qty, $cost);
            foreach ($links as [$allocation,$money]) {
                $this->stock->link($doc, $allocation, $money, $layer->id, null, bcdiv($qty, $dispatch->base_quantity, 10));
            }
            $line->update(['stock_movement_id' => $movement->id]);
            $this->stock->refresh($p->id);
            $total = bcadd($total, $cost, 4);
            $used[$dispatch->id] = bcadd($used[$dispatch->id] ?? '0', $qty, 6);
        }
        $this->journals->post($doc, [$this->account('inventory') => $total, $this->account('sales_cogs') => D::negative($total, 4)], 'operational', $customer->party_id, $customer->id, $date);
    }

    private function validateCredits(SalesCreditNote $header, string $date): void
    {
        $total = '0';
        $amounts = [];
        $quantities = [];
        $returns = [];
        foreach ($header->lines()->get() as $line) {
            $match = SalesInvoiceDispatchMatch::with('line.invoice.document')->lockForUpdate()->findOrFail($line->sales_invoice_dispatch_match_id);
            if ($match->line->document_id !== $header->sales_invoice_id || $match->sales_invoice_line_id !== $line->sales_invoice_line_id || $match->line->invoice->document->status !== 'posted') {
                G::fail('Credit provenance differs from the original invoice.');
            }
            $prior = $this->effective('sales_credit_note_lines', 'sales_invoice_dispatch_match_id', $match->id, 'net_amount');
            $amounts[$match->id] = bcadd($amounts[$match->id] ?? $prior, $line->net_amount, 4);
            if (bccomp($amounts[$match->id], $match->net_amount, 4) > 0 || bccomp($line->net_amount, '0', 4) <= 0) {
                G::fail('Credit exceeds the invoice match value still available.');
            }
            if ($header->credit_kind === 'physical_return') {
                $returned = SalesReturnLine::with('return.document')->lockForUpdate()->findOrFail($line->sales_return_line_id ?? 0);
                if ($returned->return->document->status !== 'posted' || $returned->return->document->document_date->format('Y-m-d') > $date || $returned->sales_dispatch_line_id !== $match->sales_dispatch_line_id) {
                    G::fail('Physical credit requires a confirmed return from the matched dispatch.');
                }
                $qty = $line->credited_base_quantity;
                if (bccomp($qty, '0', 6) <= 0 || bccomp($qty, bcadd($qty, '0', 0), 6)) {
                    G::fail('Physical return credit needs a positive whole quantity.');
                }
                $returns[$returned->id] = bcadd($returns[$returned->id] ?? $this->effective('sales_credit_note_lines', 'sales_return_line_id', $returned->id, 'credited_base_quantity'), $qty, 6);
                $quantities[$match->id] = bcadd($quantities[$match->id] ?? $this->effective('sales_credit_note_lines', 'sales_invoice_dispatch_match_id', $match->id, 'credited_base_quantity'), $qty, 6);
                if (bccomp($returns[$returned->id], $returned->base_quantity, 6) > 0 || bccomp($quantities[$match->id], $match->matched_base_quantity, 6) > 0 || bccomp($line->net_amount, $this->proportion($match->net_amount, $qty, $match->matched_base_quantity), 4) > 0) {
                    G::fail('Physical credit exceeds returned quantity or original proportional invoice value.');
                }
            } elseif ($line->sales_return_line_id || bccomp($line->credited_base_quantity, '0', 6)) {
                G::fail('Price adjustment credits have zero physical quantity and no return link.');
            }
            $total = bcadd($total, $line->net_amount, 4);
        }
        $header->update(['net_amount' => $total, 'gross_amount' => $total]);
    }

    public function cancel(int $actor, int $id, int $version, string $reason): void
    {
        validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:500'])->validate();
        $this->transaction($actor, 'sales.documents.manage', function () use ($actor, $id, $version, $reason) {
            $doc = Document::lockForUpdate()->findOrFail($id);
            $this->kind($doc);
            if ($doc->status !== 'draft' || $doc->lock_version !== $version) {
                G::fail('Only the current unfinished draft can be cancelled.');
            }
            $doc->update(['status' => 'cancelled', 'reason' => $reason, 'lock_version' => $version + 1]);
            $this->documents->event($doc, 'cancelled', $actor, (string) Str::uuid(), 'draft', 'cancelled', $reason);
        });
    }

    public function close(int $actor, int $id, string $reason): void
    {
        validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:500'])->validate();
        $this->transaction($actor, 'sales.documents.confirm', function () use ($actor, $id, $reason) {
            $doc = Document::lockForUpdate()->findOrFail($id);
            if ($this->kind($doc) !== 'order' || $doc->status !== 'posted') {
                G::fail('Only confirmed sales orders can close their unfulfilled balance.');
            }
            if ($this->closed($id)) {
                return;
            }
            $this->reservations->release($actor, $doc, null, $reason);
            $this->documents->event($doc, 'sales_order_closed', $actor, (string) Str::uuid(), 'posted', 'posted', $reason);
        });
    }

    public function reserve(int $actor, int $orderId, array $input): void
    {
        $this->transaction($actor, 'sales.stock.reserve', function () use ($actor, $orderId, $input) {
            $data = validator($input, ['key' => 'nullable|uuid', 'sales_order_line_id' => 'required|integer', 'stock_position_id' => 'required|integer', 'quantity' => 'required|decimal:0,6|gt:0', 'expires_at' => 'nullable|date|after:now'])->validate();
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            if (! empty($data['key']) && ($prior = AuditEvent::where('document_id', $orderId)->where('action', 'sales_reservation_created')->where('after_json->key', $data['key'])->first())) {
                if (($prior->after_json['input_hash'] ?? null) !== $hash) {
                    G::fail('Reservation key was reused with different data.');
                }

                return;
            }
            $order = SalesOrder::with('document')->findOrFail($orderId);
            $this->credit($this->customer($order->customer_party_role_id));
            $line = $this->orderLine($data['sales_order_line_id'], $order->customer_party_role_id, G::businessDate());
            if ($line->document_id !== $orderId) {
                G::fail('Reservation line belongs to another order.');
            }
            $open = bcsub($line->base_quantity, $this->dispatched($line->id), 6);
            $this->reservations->reserve($actor, $order->document, $line, $data, $open);
            if (! empty($data['key'])) {
                $this->audit->record('documents', $orderId, 'sales_reservation_created', null, ['key' => $data['key'], 'input_hash' => $hash], $actor, $orderId);
            }
        });
    }

    public function release(int $actor, int $id, string $reason): void
    {
        validator(['reason' => $reason], ['reason' => 'required|string|min:5|max:500'])->validate();
        $this->transaction($actor, 'sales.stock.reserve', function () use ($actor, $id, $reason) {
            $doc = Document::lockForUpdate()->findOrFail($id);
            if ($this->kind($doc) !== 'order') {
                G::fail('Release reservations against their sales order.');
            }
            $this->reservations->release($actor, $doc, null, $reason);
        });
    }
}
