<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\PartyOpenItem;
use App\Models\SettlementAllocation;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Modules\Catalog\Services\CatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FinanceFixtures;
use Tests\TestCase;

class DirectFabricPurchaseTest extends TestCase
{
    use FinanceFixtures, RefreshDatabase;

    protected Item $processed;

    protected Item $component;

    protected function setUp(): void
    {
        parent::setUp();
        $this->financeFixtures();
        $this->processed = app(CatalogService::class)->save('items', [
            'sku' => 'PROC-DIRECT', 'name' => 'Ready dyed cotton', 'category_id' => $this->item->category_id,
            'item_type' => 'processed_fabric', 'base_unit_id' => $this->item->base_unit_id,
            'is_stocked' => true, 'track_rolls' => false, 'is_active' => true,
            'fabric' => ['composition' => '100% cotton'],
        ]);
        $this->component = app(CatalogService::class)->save('items', [
            'sku' => 'DHANK-DUPATTA', 'name' => 'Dhank Dupatta', 'category_id' => $this->item->category_id,
            'item_type' => 'trim', 'base_unit_id' => Unit::where('code', 'PCS')->value('id'),
            'is_stocked' => true, 'track_rolls' => false, 'is_active' => true,
        ]);
    }

    private function data(array $replace = []): array
    {
        return array_replace([
            'idempotency_key' => (string) Str::uuid(), 'document_date' => $this->date,
            'supplier_party_role_id' => $this->supplier->id, 'warehouse_location_id' => $this->source->id,
            'lines' => [['item_id' => $this->processed->id, 'quantity' => '10', 'unit_cost' => '12', 'lot_number' => 'DIRECT-LOT-1']],
            'supplier_invoice_number' => 'DIRECT-INV-1',
            'supplier_invoice_date' => $this->date, 'due_on' => $this->date, 'payment_status' => 'unpaid',
        ], $replace);
    }

    public function test_inventory_screen_posts_direct_receipt_and_supplier_bill(): void
    {
        $beforeStock = (string) StockMovement::sum('quantity_delta');
        $this->get(route('inventory.direct-fabric-purchase.create'))->assertOk()->assertSee('Direct processed-fabric purchase');
        $this->post(route('inventory.direct-fabric-purchase.store'), $this->data())->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Document::where('status', 'posted')->whereHas('type', fn ($q) => $q->where('module_code', 'purchasing'))->count());
        $this->assertSame(bcadd($beforeStock, '10', 6), bcadd((string) StockMovement::sum('quantity_delta'), '0', 6));
        $this->assertSame('120.0000', PartyOpenItem::firstOrFail()->original_amount);
        $this->assertSame(0, SettlementAllocation::count());
    }

    public function test_paid_purchase_posts_and_allocates_cash_payable_voucher(): void
    {
        $before = Document::where('status', 'posted')->count();
        $this->post(route('inventory.direct-fabric-purchase.store'), $this->data([
            'payment_status' => 'paid', 'payment_amount' => '120', 'bank_account_id' => $this->bank->id,
            'payment_method' => 'cash', 'payment_reference' => 'CASH-1',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($before + 4, Document::where('status', 'posted')->count()); // GRN, bill, payment and allocation.
        $this->assertSame(1, SettlementAllocation::count());
        $bill = PartyOpenItem::whereHas('journalLine.document.type', fn ($q) => $q->where('code', 'purchase_invoice'))->firstOrFail();
        $this->assertSame('0.0000', app(\App\Modules\Accounting\Services\FinanceService::class)->outstanding($bill->id));
    }

    public function test_multiple_piece_lots_are_grouped_and_expandable_in_inventory(): void
    {
        $this->post(route('inventory.direct-fabric-purchase.store'), $this->data([
            'lines' => [
                ['item_id' => $this->component->id, 'quantity' => '86', 'unit_cost' => '5', 'lot_number' => 'DHANK-LAAT-1'],
                ['item_id' => $this->component->id, 'quantity' => '14', 'unit_cost' => '5', 'lot_number' => 'DHANK-LAAT-2'],
            ],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, GoodsReceiptLine::count());
        $this->get(route('inventory.index'))->assertOk()->assertSee('Dhank Dupatta')->assertSee('100.000000 PCS')->assertSee('DHANK-LAAT-1')->assertSee('DHANK-LAAT-2');
    }
}
