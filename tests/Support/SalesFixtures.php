<?php

namespace Tests\Support;

use App\Models\CustomerProfile;
use App\Models\Document;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\PartyRoleType;
use App\Models\PaymentTerm;
use App\Models\ProductionOutputLine;
use App\Models\SalesDispatch;
use App\Models\SalesOrder;
use App\Modules\Sales\Services\SalesService;
use Database\Seeders\SalesConfigurationSeeder;
use Illuminate\Support\Str;

trait SalesFixtures
{
    use ProductionFixtures;

    protected SalesService $sales;

    protected PartyRole $customer;

    protected int $sellPosition;

    protected function salesFixtures(): void
    {
        $this->productionFixtures();
        $this->seed(SalesConfigurationSeeder::class);
        $party = Party::create(['code' => 'CUS-TEST', 'legal_name' => 'Wholesale test customer', 'party_kind' => 'organization', 'is_active' => true]);
        $this->customer = PartyRole::create(['party_id' => $party->id, 'party_role_type_id' => PartyRoleType::where('code', 'customer')->value('id'), 'is_active' => true]);
        CustomerProfile::create(['party_role_id' => $this->customer->id, 'payment_term_id' => PaymentTerm::first()->id, 'credit_limit' => '1000000', 'credit_hold' => false]);
        $batch = $this->batch();
        $pos = $this->position($this->opening('600', '10'));
        $report = $this->production->confirm($this->admin->id, $this->reportData($batch, $pos, '600', '100', true));
        $this->sellPosition = ProductionOutputLine::where('document_id', $report->id)->value('stock_position_id');
        $this->sales = app(SalesService::class);
    }

    protected function salesData(string $kind, array $lines): array
    {
        return ['kind' => $kind, 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'customer_party_role_id' => $this->customer->id, 'warehouse_id' => $this->destination->warehouse_id, 'ship_to_snapshot' => 'Customer delivery address', 'billing_address_snapshot' => 'Customer billing address', 'reason' => 'Actual returned goods or approved adjustment', 'lines' => $lines];
    }

    protected function salesOrder(string $qty = '100', string $price = '100', string $discount = '0'): Document
    {
        return $this->sales->confirm($this->admin->id, $this->salesData('order', [['item_id' => $this->variant->item_id, 'quantity' => $qty, 'unit_price' => $price, 'discount_amount' => $discount]]));
    }

    protected function salesDispatch(Document $order, string $qty = '40'): Document
    {
        return $this->sales->confirm($this->admin->id, $this->dispatchData($order, $qty));
    }

    protected function dispatchData(Document $order, string $qty = '40'): array
    {
        return $this->salesData('dispatch', [['sales_order_line_id' => SalesOrder::findOrFail($order->id)->lines->first()->id, 'stock_position_id' => $this->sellPosition, 'base_quantity' => $qty]]);
    }

    protected function invoiceData(Document $dispatch, string $qty = '40'): array
    {
        return $this->salesData('invoice', [['sales_dispatch_line_id' => SalesDispatch::findOrFail($dispatch->id)->lines->first()->id, 'base_quantity' => $qty]]);
    }
}
