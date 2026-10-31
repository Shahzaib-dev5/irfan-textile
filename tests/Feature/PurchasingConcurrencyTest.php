<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\PartyOpenItem;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Tests\Support\DatabaseRace;
use Tests\Support\PurchasingFixtures;
use Tests\TestCase;

class PurchasingConcurrencyTest extends TestCase
{
    use DatabaseRace,PurchasingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.connections.mysql.database') !== 'irfan_textile_phase3_test') {
            throw new \LogicException('Race tests may reset only the isolated test database.');
        }
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->purchasingFixtures();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_simultaneous_direct_confirmation_retry_creates_one_order_and_number(): void
    {
        $data = ['kind' => 'order', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'supplier_party_role_id' => $this->supplier->id, 'warehouse_id' => $this->source->warehouse_id, 'lines' => [['item_id' => $this->item->id, 'unit_id' => $this->item->base_unit_id, 'quantity' => '100', 'unit_price' => '10']]];
        $task = ['action' => 'purchase_confirm', 'input' => $data];
        $results = $this->race([$task, $task]);
        $this->assertSame(['posted', 'posted'], array_column($results, 'result'), json_encode($results));
        $this->assertSame(1, PurchaseOrder::count());
        $this->assertSame(1, Document::where('status', 'posted')->whereNotNull('number')->count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_competing_receipts_cannot_over_receive_a_shared_order(): void
    {
        $order = $this->order();
        $a = $this->goods($order, '60', '0', false);
        $b = $this->goods($order, '60', '0', false);
        $results = $this->race([['action' => 'purchase_post', 'document' => $a->id, 'key' => (string) Str::uuid()], ['action' => 'purchase_post', 'document' => $b->id, 'key' => (string) Str::uuid()]]);
        $outcomes = array_column($results, 'result');
        sort($outcomes);
        $this->assertSame(['posted', 'rejected'], $outcomes, json_encode($results));
        $this->assertSame(1, StockMovement::count());
        $this->assertSame('60.000000', (string) StockMovement::sum('quantity_delta'));
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_competing_bills_cannot_consume_receipt_matching_capacity_twice(): void
    {
        $receipt = $this->goods($this->order());
        $a = $this->supplierBill($receipt, '60', '10', false);
        $b = $this->supplierBill($receipt, '60', '10', false);
        $results = $this->race([['action' => 'purchase_post', 'document' => $a->id, 'key' => (string) Str::uuid()], ['action' => 'purchase_post', 'document' => $b->id, 'key' => (string) Str::uuid()]]);
        $outcomes = array_column($results, 'result');
        sort($outcomes);
        $this->assertSame(['posted', 'rejected'], $outcomes, json_encode($results));
        $this->assertSame(1, PartyOpenItem::count());
        $this->assertSame('600.0000', PartyOpenItem::first()->original_amount);
        $this->assertSame(1, StockMovement::count());
    }
}
