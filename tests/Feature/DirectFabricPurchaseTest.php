<?php

namespace Tests\Feature;

use App\Models\AccessRole;
use App\Models\Document;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\PartyOpenItem;
use App\Models\Permission;
use App\Models\SettlementAllocation;
use App\Models\StockMovement;
use App\Models\TextileDefinition;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Accounting\Services\FinanceService;
use App\Modules\Catalog\Services\CatalogService;
use App\Modules\Purchasing\Services\PurchasingService;
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

    public function test_inline_fabric_receipt_without_invoice_can_be_billed_later_without_replaying_stock(): void
    {
        $data = $this->data(['supplier_invoice_number' => null, 'supplier_invoice_date' => null, 'due_on' => null,
            'lines' => [['new_item' => ['sku' => 'INLINE-LAWN', 'name' => 'Lawn front back', 'item_type' => 'processed_fabric', 'fabric_type' => 'Cotton lawn'], 'unit_id' => Unit::where('code', 'SET')->value('id'), 'quantity' => '120', 'unit_cost' => '12000', 'rate_unit_id' => Unit::where('code', 'THAAN')->value('id'), 'rate_quantity' => '10', 'thaan_count' => 10, 'set_count' => 120, 'measured_meters' => '600', 'lot_number' => 'INLINE-LOT']],
        ]);
        $this->post(route('inventory.direct-fabric-purchase.store'), $data)->assertSessionHasNoErrors();
        $item = Item::where('sku', 'INLINE-LAWN')->firstOrFail();
        $this->assertSame('processed_fabric', $item->item_type);
        $this->assertSame('SET', $item->baseUnit->code);
        $this->assertSame('Cotton lawn', $item->textileDefinition->fabric_type);
        $line = GoodsReceiptLine::firstOrFail();
        $this->assertSame('120.000000', $line->accepted_base_quantity);
        $this->assertSame('120000.0000', $line->provisional_value);
        $this->assertSame(0, PartyOpenItem::count());
        $this->assertDatabaseHas('receipt_measurements', ['goods_receipt_line_id' => $line->id, 'thaan_count' => 10, 'set_count' => 120, 'measured_meters' => '600.000000']);
        $this->get(route('purchasing.show', $line->document_id))->assertOk()->assertSee('Create supplier bill')->assertSee('Original received measurements')->assertSee('600.000000');
        $before = StockMovement::count();
        $this->post(route('inventory.direct-fabric-purchase.store'), $data)->assertSessionHasNoErrors();
        $this->assertSame($before, StockMovement::count());
        $bill = app(PurchasingService::class)->confirm($this->admin->id, [
            'kind' => 'bill', 'idempotency_key' => (string) Str::uuid(), 'document_date' => $this->date,
            'supplier_party_role_id' => $this->supplier->id, 'supplier_invoice_number' => 'LATER-1', 'supplier_invoice_date' => $this->date, 'due_on' => $this->date,
            'lines' => [['goods_receipt_line_id' => $line->id, 'quantity' => '120', 'unit_price' => '1000', 'discount_amount' => '0']],
        ]);
        $this->assertSame('posted', $bill->status);
        $this->assertSame($before, StockMovement::count());
        $this->assertSame('120000.0000', PartyOpenItem::firstOrFail()->original_amount);
        $data['lines'][0]['measured_meters'] = '610';
        $this->post(route('inventory.direct-fabric-purchase.store'), $data)->assertSessionHasErrors('idempotency_key');
    }

    public function test_same_fabric_can_arrive_in_meters_pieces_and_thaan_with_separate_costed_stock(): void
    {
        $this->post(route('inventory.direct-fabric-purchase.store'), $this->data())->assertSessionHasNoErrors();
        foreach (['PCS', 'THAAN'] as $code) {
            $this->post(route('inventory.direct-fabric-purchase.store'), $this->data([
                'supplier_invoice_number' => 'INV-'.$code,
                'lines' => [['item_id' => $this->processed->id, 'unit_id' => Unit::where('code', $code)->value('id'), 'quantity' => '5', 'unit_cost' => '20', 'lot_number' => 'LOT-'.$code]],
            ]))->assertSessionHasNoErrors();
        }
        $group = $this->processed->fresh()->textileDefinition;
        $this->assertSame(3, $group->items()->count());
        $this->assertSame(1, TextileDefinition::count());
        $this->assertSame('M', $this->processed->fresh()->baseUnit->code);
        $this->assertSame('320.0000', bcadd((string) GoodsReceiptLine::sum('provisional_value'), '0', 4));
        $this->get(route('inventory.direct-fabric-purchase.create'))->assertOk()->assertViewHas('items', fn ($items) => $items->where('textile_definition_id', $group->id)->count() === 1);
        $this->get(route('inventory.index'))->assertOk()->assertSee('10.000000 M')->assertSee('5.000000 PCS')->assertSee('5.000000 THAAN');
    }

    public function test_invalid_measurements_roll_back_inline_item_and_receipt(): void
    {
        $data = $this->data(['lines' => [['new_item' => ['sku' => 'BAD-INLINE', 'name' => 'Grey pieces', 'item_type' => 'grey_fabric'], 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'quantity' => '1.5', 'unit_cost' => '10', 'lot_number' => 'BAD-INLINE']]]);
        $before = StockMovement::count();
        $this->post(route('inventory.direct-fabric-purchase.store'), $data)->assertSessionHasErrors();
        $this->assertDatabaseMissing('items', ['sku' => 'BAD-INLINE']);
        $this->assertDatabaseMissing('textile_definitions', ['code' => 'BAD-INLINE']);
        $this->assertSame($before, StockMovement::count());
        $data['lines'][0]['quantity'] = '10';
        $data['lines'][0]['rate_unit_id'] = Unit::where('code', 'THAAN')->value('id');
        $data['lines'][0]['rate_quantity'] = '2';
        $this->post(route('inventory.direct-fabric-purchase.store'), $data)->assertSessionHasErrors('lines');
        $this->assertDatabaseMissing('textile_definitions', ['code' => 'BAD-INLINE']);
        unset($data['lines'][0]['rate_unit_id'], $data['lines'][0]['rate_quantity']);
        $this->post(route('inventory.direct-fabric-purchase.store'), $data)->assertSessionHasNoErrors();
        $this->assertSame('grey_fabric', Item::where('sku', 'BAD-INLINE')->firstOrFail()->item_type);
    }

    public function test_receipt_operator_can_receive_without_bill_permission_but_cannot_create_items_or_bills(): void
    {
        $role = AccessRole::create(['code' => 'receipt-only', 'name' => 'Receipt only']);
        $role->permissions()->attach(Permission::whereIn('code', ['inventory.view', 'purchasing.documents.manage', 'purchasing.receipts.post'])->pluck('id'));
        $user = User::factory()->create();
        $user->accessRoles()->attach($role->id, ['assigned_by' => $this->admin->id]);
        $this->actingAs($user)->get(route('inventory.direct-fabric-purchase.create'))->assertOk()->assertDontSee('Add new item here');
        $data = $this->data(['supplier_invoice_number' => null, 'supplier_invoice_date' => null, 'due_on' => null]);
        $this->post(route('inventory.direct-fabric-purchase.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('inventory.direct-fabric-purchase.store'), $this->data())->assertForbidden();
        $data['idempotency_key'] = (string) Str::uuid();
        $data['lines'][0]['unit_id'] = Unit::where('code', 'PCS')->value('id');
        $data['lines'][0]['lot_number'] = 'NO-CATALOG-ACCESS';
        $this->post(route('inventory.direct-fabric-purchase.store'), $data)->assertForbidden();
        $this->assertSame(0, TextileDefinition::count());
    }

    public function test_payment_without_invoice_is_rejected_without_partial_stock(): void
    {
        $before = StockMovement::count();
        $this->post(route('inventory.direct-fabric-purchase.store'), $this->data([
            'supplier_invoice_number' => null, 'supplier_invoice_date' => null, 'due_on' => null,
            'payment_status' => 'paid', 'payment_amount' => '120', 'bank_account_id' => $this->bank->id, 'payment_method' => 'cash',
        ]))->assertSessionHasErrors('payment_status');
        $this->assertSame($before, StockMovement::count());
        $this->assertSame(0, GoodsReceiptLine::count());
    }

    public function test_inventory_screen_posts_direct_receipt_and_supplier_bill(): void
    {
        $beforeStock = (string) StockMovement::sum('quantity_delta');
        $form = $this->get(route('inventory.direct-fabric-purchase.create'))->assertOk()->assertSee('Direct fabric / material purchase')->assertSee('Main Warehouse')->assertDontSee('Choose warehouse / location');
        if (getenv('DIRECT_PURCHASE_BROWSER_FIXTURES')) {
            $path = storage_path('framework/testing/direct-purchase-browser');
            if (! is_dir($path)) {
                mkdir($path, 0777, true);
            }
            file_put_contents($path.'/create.html', $form->getContent());
        }
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
        $this->assertSame('0.0000', app(FinanceService::class)->outstanding($bill->id));
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

    public function test_processed_fabric_can_be_purchased_as_whole_pieces_with_supplier_cost(): void
    {
        $pieces = app(CatalogService::class)->save('items', [
            'sku' => 'EMB-PCS', 'name' => 'Embroidered fabric pieces', 'category_id' => $this->item->category_id,
            'item_type' => 'processed_fabric', 'base_unit_id' => Unit::where('code', 'PCS')->value('id'),
            'is_stocked' => true, 'track_rolls' => false, 'is_active' => true,
            'fabric' => ['composition' => '100% cotton'],
        ]);
        $data = $this->data(['lines' => [['item_id' => $pieces->id, 'quantity' => '10.5', 'unit_cost' => '12', 'lot_number' => 'EMB-PCS-LOT']]]);
        $before = StockMovement::count();
        $this->post(route('inventory.direct-fabric-purchase.store'), $data)->assertSessionHasErrors();
        $this->assertSame($before, StockMovement::count());
        $this->assertSame(0, GoodsReceiptLine::count());
        $data['lines'][0]['quantity'] = '10';
        $this->post(route('inventory.direct-fabric-purchase.store'), $data)->assertSessionHasNoErrors();
        $this->assertSame('10.000000', GoodsReceiptLine::firstOrFail()->accepted_base_quantity);
        $this->assertSame('120.0000', PartyOpenItem::firstOrFail()->original_amount);
        $this->get(route('inventory.index'))->assertOk()->assertSee('Embroidered fabric pieces')->assertSee('10.000000 PCS');
    }
}
