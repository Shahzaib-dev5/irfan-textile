<?php

namespace Tests\Feature;

use App\Models\AccountingPeriod;
use App\Models\CustomerProfile;
use App\Models\InventoryValueEntry;
use App\Models\Item;
use App\Models\ItemUnitConversion;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\PostingAccount;
use App\Models\SalesDispatch;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\StockCostLayer;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Unit;
use App\Models\WarehouseLocation;
use App\Modules\FinishedGoods\Services\ActualCosting;
use App\Modules\Inventory\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\SalesFixtures;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase,SalesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    private function invalid(callable $f, string $contains = ''): void
    {
        try {
            $f();
            $this->fail('Expected validation failure');
        } catch (ValidationException $e) {
            if ($contains) {
                $this->assertStringContainsString($contains, json_encode($e->errors()));
            } else {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_partial_dispatch_and_invoice_post_stock_revenue_and_ar_once(): void
    {
        $order = $this->salesOrder('100', '100', '100');
        $data = $this->dispatchData($order, '40');
        $dispatch = $this->sales->confirm($this->admin->id, $data);
        $this->assertSame($dispatch->id, $this->sales->confirm($this->admin->id, $data)->id);
        $this->assertSame('60.000000', app(StockLedger::class)->onHand($this->sellPosition));
        $this->assertSame('3960.0000', SalesDispatch::find($dispatch->id)->lines->first()->net_revenue_amount);
        $invoiceData = $this->invoiceData($dispatch);
        $invoice = $this->sales->confirm($this->admin->id, $invoiceData);
        $this->assertSame($invoice->id, $this->sales->confirm($this->admin->id, $invoiceData)->id);
        $this->assertSame('3960.0000', SalesInvoice::find($invoice->id)->net_amount);
        $this->assertSame(0, StockMovement::where('document_id', $invoice->id)->count());
        $this->assertSame('9900.0000', $this->sales->exposure($this->customer->id));
        $this->invalid(fn () => $this->sales->confirm($this->admin->id, $this->invoiceData($dispatch, '1')), 'exceeds');
    }

    public function test_reservations_protect_other_orders_and_partial_fulfillment_releases_only_own_stock(): void
    {
        $a = $this->salesOrder('80');
        $b = $this->salesOrder('80');
        $line = SalesOrder::find($a->id)->lines->first();
        $this->sales->reserve($this->admin->id, $a->id, ['sales_order_line_id' => $line->id, 'stock_position_id' => $this->sellPosition, 'quantity' => '80']);
        $this->invalid(fn () => $this->salesDispatch($b, '30'), 'unreserved');
        $this->salesDispatch($a, '30');
        $this->assertSame('50.000000', app(StockLedger::class)->reserved($this->sellPosition));
        $this->assertSame('30.000000', StockReservation::first()->fulfilled_quantity);
        $this->sales->close($this->admin->id, $a->id, 'Customer cancelled the remaining balance');
        $this->assertSame('0.000000', app(StockLedger::class)->reserved($this->sellPosition));
        $this->invalid(fn () => $this->salesDispatch($a, '1'), 'open sales order');
        $this->salesDispatch($b, '70');
        $this->assertSame('0.000000', app(StockLedger::class)->onHand($this->sellPosition));
    }

    public function test_credit_controls_include_commitments_and_hold_without_overrides(): void
    {
        CustomerProfile::whereKey($this->customer->id)->update(['credit_limit' => 5000]);
        $o = $this->salesOrder('40');
        $this->invalid(fn () => $this->salesOrder('20'), 'credit limit');
        $dispatch = $this->salesDispatch($o, '20');
        $this->sales->confirm($this->admin->id, $this->invoiceData($dispatch, '20'));
        $this->assertSame('4000.0000', $this->sales->exposure($this->customer->id));
        CustomerProfile::whereKey($this->customer->id)->update(['credit_hold' => true]);
        $this->invalid(fn () => $this->salesDispatch($o, '20'), 'credit hold');
    }

    public function test_physical_return_restores_original_cost_and_credit_reduces_ar_without_second_stock_receipt(): void
    {
        $o = $this->salesOrder('40');
        $d = $this->salesDispatch($o);
        $invoice = $this->sales->confirm($this->admin->id, $this->invoiceData($d));
        $dispatch = SalesDispatch::find($d->id)->lines->first();
        $data = $this->salesData('return', [['sales_dispatch_line_id' => $dispatch->id, 'base_quantity' => '10', 'warehouse_location_id' => $this->destination->id, 'lot_number' => 'RETURN-TEST', 'condition_code' => 'saleable']]);
        $ret = $this->sales->confirm($this->admin->id, $data);
        $this->assertSame('600.0000', bcadd((string) InventoryValueEntry::where('document_id', $ret->id)->sum('amount_pkr'), '0', 4));
        $this->assertSame('4000.0000', $this->sales->exposure($this->customer->id));
        $match = SalesInvoice::find($invoice->id)->lines->first()->matches->first();
        $credit = $this->salesData('credit', [['sales_invoice_dispatch_match_id' => $match->id, 'sales_return_line_id' => SalesReturn::find($ret->id)->lines->first()->id, 'credited_base_quantity' => '10', 'net_amount' => '1000']]) + ['sales_invoice_id' => $invoice->id, 'credit_kind' => 'physical_return'];
        $c = $this->sales->confirm($this->admin->id, $credit);
        $this->assertSame('3000.0000', $this->sales->exposure($this->customer->id));
        $this->assertSame(0, StockMovement::where('document_id', $c->id)->count());
        $this->invalid(fn () => $this->sales->confirm($this->admin->id, array_replace($credit, ['idempotency_key' => (string) Str::uuid()])), 'returned quantity');
    }

    public function test_over_dispatch_over_return_and_wrong_customer_are_atomic(): void
    {
        $o = $this->salesOrder('20');
        $this->invalid(fn () => $this->salesDispatch($o, '21'), 'unfulfilled');
        $this->assertSame(0, SalesDispatch::count());
        $d = $this->salesDispatch($o, '20');
        $input = $this->salesData('return', [['sales_dispatch_line_id' => SalesDispatch::find($d->id)->lines->first()->id, 'base_quantity' => '21', 'warehouse_location_id' => $this->destination->id, 'lot_number' => 'BAD-RETURN', 'condition_code' => 'saleable']]);
        $this->invalid(fn () => $this->sales->confirm($this->admin->id, $input), 'originally dispatched');
        $this->assertSame(0, SalesReturn::count());
        $this->customer->update(['is_active' => false]);
        $this->invalid(fn () => $this->salesOrder('1'), 'active shared');
    }

    public function test_rounding_final_dispatch_and_invoices_reconcile_discounted_order_exactly(): void
    {
        $o = $this->salesOrder('3', '1', '0.0001');
        $net = '0';
        for ($i = 0; $i < 3; $i++) {
            $d = $this->salesDispatch($o, '1');
            $invoice = $this->sales->confirm($this->admin->id, $this->invoiceData($d, '1'));
            $net = bcadd($net, SalesInvoice::find($invoice->id)->net_amount, 4);
        }
        $this->assertSame('2.9999', $net);
        $this->assertSame('2.9999', $this->sales->exposure($this->customer->id));
    }

    public function test_draft_cancellation_and_changed_key_payload_do_not_post(): void
    {
        $d = $this->salesData('order', [['item_id' => $this->variant->item_id, 'quantity' => '3', 'unit_price' => '1']]);
        $doc = $this->sales->save($this->admin->id, $d);
        $bad = $d;
        $bad['lines'][0]['quantity'] = '4';
        $this->invalid(fn () => $this->sales->confirm($this->admin->id, $bad), 'different data');
        $this->sales->cancel($this->admin->id, $doc->id, 0, 'Duplicate draft entry was created');
        $this->assertSame('cancelled', $doc->fresh()->status);
        $this->invalid(fn () => $this->sales->post($this->admin->id, $doc->id, $d['idempotency_key'], 0), 'already confirmed');
    }

    public function test_wholesale_rejects_fabric_fractional_suits_and_excessive_discounts(): void
    {
        $base = $this->salesData('order', [['item_id' => $this->item->id, 'quantity' => '1', 'unit_price' => '100']]);
        $this->invalid(fn () => $this->sales->confirm($this->admin->id, $base), 'finished SUIT');
        $this->invalid(fn () => $this->salesOrder('0.5'), 'whole finished');
        $this->invalid(fn () => $this->salesOrder('1', '100', '101'), 'Discount');
        $missing = $base;
        $missing['lines'][0]['item_id'] = $this->variant->item_id;
        unset($missing['lines'][0]['unit_price']);
        $this->invalid(fn () => $this->sales->confirm($this->admin->id, $missing), 'unit_price');
    }

    public function test_expired_reservations_require_explicit_release_before_dispatch(): void
    {
        $o = $this->salesOrder('10');
        $line = SalesOrder::find($o->id)->lines->first();
        $this->sales->reserve($this->admin->id, $o->id, ['sales_order_line_id' => $line->id, 'stock_position_id' => $this->sellPosition, 'quantity' => '10', 'expires_at' => now()->addMinute()->format('Y-m-d H:i:s')]);
        $this->travel(2)->minutes();
        $this->invalid(fn () => $this->salesDispatch($o, '10'), 'expired');
        $this->sales->release($this->admin->id, $o->id, 'Expired reservation needs replacement');
        $this->salesDispatch($o, '10');
        $this->assertSame('90.000000', app(StockLedger::class)->onHand($this->sellPosition));
    }

    public function test_damaged_returns_stay_quarantined_and_keep_original_source_cost_trace(): void
    {
        $o = $this->salesOrder('10');
        $d = $this->salesDispatch($o, '10');
        $quarantine = WarehouseLocation::factory()->create(['warehouse_id' => $this->destination->warehouse_id, 'location_type' => 'quarantine']);
        $ret = $this->sales->confirm($this->admin->id, $this->salesData('return', [['sales_dispatch_line_id' => SalesDispatch::find($d->id)->lines->first()->id, 'base_quantity' => '2', 'warehouse_location_id' => $quarantine->id, 'lot_number' => 'DAMAGED-RETURN', 'condition_code' => 'damaged']]));
        $p = SalesReturn::find($ret->id)->lines->first()->stock_position_id;
        $new = $this->salesOrder('2');
        $input = $this->dispatchData($new, '2');
        $input['lines'][0]['stock_position_id'] = $p;
        $this->invalid(fn () => $this->sales->confirm($this->admin->id, $input), 'released stock');
        $layer = StockCostLayer::where('stock_position_id', $p)->firstOrFail();
        $report = app(ActualCosting::class)->acquisition($layer->id);
        $this->assertSame('120.0000', app(ActualCosting::class)->total($report));
    }

    public function test_pack_sales_issue_pack_stock_without_a_second_suit_issue(): void
    {
        $pack = Item::factory()->create(['item_type' => 'finished', 'base_unit_id' => Unit::where('code', 'PACK')->value('id'), 'track_rolls' => false, 'is_stocked' => true]);
        $original = $this->item;
        $this->item = $pack;
        $p = $this->position($this->opening('5', '62'));
        $this->item = $original;
        $o = $this->sales->confirm($this->admin->id, $this->salesData('order', [['item_id' => $pack->id, 'quantity' => '3', 'unit_price' => '100']]));
        $data = $this->dispatchData($o, '3');
        $data['warehouse_id'] = $this->source->warehouse_id;
        $data['lines'][0]['stock_position_id'] = $p;
        $this->sales->confirm($this->admin->id, $data);
        $this->assertSame('2.000000', app(StockLedger::class)->onHand($p));
        $this->assertSame('100.000000', app(StockLedger::class)->onHand($this->sellPosition));
    }

    public function test_order_can_backorder_unavailable_stock_but_dispatch_is_blocked_and_closed_periods_reject_posting(): void
    {
        $o = $this->salesOrder('200');
        $this->invalid(fn () => $this->salesDispatch($o, '101'), 'unreserved');
        $draft = $this->sales->save($this->admin->id, $this->salesData('order', [['item_id' => $this->variant->item_id, 'quantity' => '1', 'unit_price' => '100']]));
        AccountingPeriod::whereKey($draft->accounting_period_id)->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $this->admin->id]);
        $this->invalid(fn () => $this->sales->post($this->admin->id, $draft->id, $draft->idempotency_key, 0), 'open year');
    }

    public function test_alternate_suit_set_conversion_is_snapshotted_and_partial_invoice_preserves_original_price(): void
    {
        $set = Unit::where('code', 'SET')->firstOrFail();
        $c = ItemUnitConversion::create(['item_id' => $this->variant->item_id, 'unit_id' => $set->id, 'factor_to_base' => '6']);
        $o = $this->sales->confirm($this->admin->id, $this->salesData('order', [['item_id' => $this->variant->item_id, 'unit_id' => $set->id, 'quantity' => '2', 'unit_price' => '600']]));
        $c->update(['factor_to_base' => '10']);
        $d = $this->salesDispatch($o, '5');
        $invoice = $this->sales->confirm($this->admin->id, $this->invoiceData($d, '5'));
        $this->assertSame('500.0000', SalesInvoice::find($invoice->id)->net_amount);
        $this->assertSame('12.000000', SalesOrder::find($o->id)->lines->first()->base_quantity);
    }

    public function test_dispatch_journal_honors_its_frozen_revenue_account_after_mapping_changes(): void
    {
        $o = $this->salesOrder('2');
        $data = $this->dispatchData($o, '2');
        $draft = $this->sales->save($this->admin->id, $data);
        $original = SalesDispatch::find($draft->id)->lines->first()->revenue_account_id;
        $new = LedgerAccount::create(['code' => '4009', 'name' => 'New Wholesale revenue', 'account_type' => 'income', 'normal_balance' => 'credit', 'control_type' => 'none', 'is_active' => true, 'is_postable' => true]);
        PostingAccount::where('purpose_code', 'sales_revenue')->update(['ledger_account_id' => $new->id]);
        $this->sales->post($this->admin->id, $draft->id, $data['idempotency_key'], 0);
        $this->assertSame('200.0000', JournalLine::where('journal_entry_id', $draft->id)->where('ledger_account_id', $original)->firstOrFail()->credit);
        $this->assertFalse(JournalLine::where('journal_entry_id', $draft->id)->where('ledger_account_id', $new->id)->exists());
    }

    public function test_reservation_command_retry_holds_once_and_rejects_changed_payload(): void
    {
        $o = $this->salesOrder('100');
        $line = SalesOrder::find($o->id)->lines->first();
        $data = ['key' => (string) Str::uuid(), 'sales_order_line_id' => $line->id, 'stock_position_id' => $this->sellPosition, 'quantity' => '20'];
        $this->sales->reserve($this->admin->id, $o->id, $data);
        $this->sales->reserve($this->admin->id, $o->id, $data);
        $this->assertSame(1, StockReservation::where('document_id', $o->id)->count());
        $data['quantity'] = '30';
        $this->invalid(fn () => $this->sales->reserve($this->admin->id, $o->id, $data), 'different data');
    }
}
